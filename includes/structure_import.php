<?php
/**
 * Import van de structuur-Excel (zie structure_export.php voor het format).
 * Strict, transactioneel, alleen op een lege structuur.
 *
 * De zes hoofdcategorieën zijn vast en worden hier hardcoded ingevoegd
 * (FUNC, NFR, VEND, IMPL, SUP, LIC) — Excel beheert ze niet meer.
 * Per categorie is er één tabblad met de bijbehorende subcategorieën.
 */

if (!defined('APP_BOOT')) { http_response_code(403); exit('Forbidden'); }

use PhpOffice\PhpSpreadsheet\IOFactory;

/** Vaste categorieën — code, name, type, sort_order. */
const STRUCT_FIXED_CATEGORIES = [
    ['code' => 'FUNC', 'name' => 'Functioneel',     'type' => 'functional',     'sort_order' => 10],
    ['code' => 'NFR',  'name' => 'Non-functioneel', 'type' => 'non_functional', 'sort_order' => 20],
    ['code' => 'VEND', 'name' => 'Leverancier',     'type' => 'other',          'sort_order' => 30],
    ['code' => 'IMPL', 'name' => 'Implementatie',   'type' => 'other',          'sort_order' => 35],
    ['code' => 'SUP',  'name' => 'Support',         'type' => 'other',          'sort_order' => 40],
    ['code' => 'LIC',  'name' => 'Licentie',        'type' => 'other',          'sort_order' => 50],
];

function structure_is_empty(): bool {
    $pdo = db();
    foreach (['categorieen','applicatiesoorten','subcategorie_templates','demo_question_catalog'] as $t) {
        if ((int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn() > 0) return false;
    }
    return true;
}

/**
 * Leest het uploaded xlsx-bestand en schrijft de structuur weg.
 * Gooit RuntimeException bij elke validatiefout; transactie rolt terug.
 *
 * @return array{cat:int,app:int,sub:int,demo:int}
 */
function structure_import_xlsx(string $tmpPath): array {
    if (!structure_is_empty()) {
        throw new RuntimeException('Upload alleen mogelijk op een lege structuur — gebruik eerst Wipe.');
    }

    try {
        $ss = IOFactory::load($tmpPath);
    } catch (Throwable $e) {
        throw new RuntimeException('Kon Excel niet lezen: ' . $e->getMessage());
    }

    $required = ['App soorten', 'App services', 'NFR', 'VEND', 'IMPL', 'SUP', 'LIC', 'DEMO-vragen'];
    foreach ($required as $t) {
        if ($ss->getSheetByName($t) === null) {
            throw new RuntimeException("Tabblad '$t' ontbreekt.");
        }
    }

    $apps = _struct_read_sheet($ss->getSheetByName('App soorten'),  ['name','description','bron']);
    $demo = _struct_read_sheet($ss->getSheetByName('DEMO-vragen'),  ['block','text']);

    // Subcategorie-tabs: alle hangen op dezelfde lijst, met een afgeleide
    // categorie_code. FUNC is de enige met applicatiesoort_name-kolom.
    $subcatSheets = [
        'App services' => ['code' => 'FUNC', 'cols' => ['applicatiesoort_name','name','bron','description']],
        'NFR'          => ['code' => 'NFR',  'cols' => ['name','bron','description']],
        'VEND'         => ['code' => 'VEND', 'cols' => ['name','bron','description']],
        'IMPL'         => ['code' => 'IMPL', 'cols' => ['name','bron','description']],
        'SUP'          => ['code' => 'SUP',  'cols' => ['name','bron','description']],
        'LIC'          => ['code' => 'LIC',  'cols' => ['name','bron','description']],
    ];
    $subs = []; // unified [{sheet, row, code, applicatiesoort_name?, name, bron, description}, …]
    foreach ($subcatSheets as $sheetName => $meta) {
        $rows = _struct_read_sheet($ss->getSheetByName($sheetName), $meta['cols']);
        foreach ($rows as $i => $r) {
            $subs[] = [
                '__sheet'              => $sheetName,
                '__row'                => $i + 2,
                'categorie_code'       => $meta['code'],
                'applicatiesoort_name' => trim((string)($r['applicatiesoort_name'] ?? '')),
                'name'                 => trim((string)$r['name']),
                'bron'                 => trim((string)($r['bron'] ?? '')),
                'description'          => trim((string)($r['description'] ?? '')),
            ];
        }
    }

    // ── Validatie ─────────────────────────────────────────────────
    $appByName = [];
    foreach ($apps as $i => $r) {
        $name = trim((string)$r['name']);
        if ($name === '') {
            throw new RuntimeException('App soorten rij ' . ($i + 2) . ': name is verplicht.');
        }
        if (isset($appByName[$name])) {
            throw new RuntimeException("App soorten: dubbele naam '$name'.");
        }
        $appByName[$name] = true;
    }

    foreach ($subs as $r) {
        $sheet = $r['__sheet']; $rowNo = $r['__row'];
        if ($r['name'] === '') {
            throw new RuntimeException("$sheet rij $rowNo: name is verplicht.");
        }
        if ($r['categorie_code'] === 'FUNC') {
            if ($r['applicatiesoort_name'] === '') {
                throw new RuntimeException("$sheet rij $rowNo: applicatiesoort_name is verplicht voor FUNC.");
            }
            if (!isset($appByName[$r['applicatiesoort_name']])) {
                throw new RuntimeException("$sheet rij $rowNo: onbekende applicatiesoort_name '{$r['applicatiesoort_name']}'.");
            }
        }
    }

    foreach ($demo as $i => $r) {
        $block = (int)($r['block'] ?? 0);
        $text  = trim((string)$r['text']);
        if ($block < 1) {
            throw new RuntimeException('DEMO-vragen rij ' . ($i + 2) . ': block moet >= 1 zijn.');
        }
        if ($text === '') {
            throw new RuntimeException('DEMO-vragen rij ' . ($i + 2) . ': text is verplicht.');
        }
    }

    // ── Schrijven (transactioneel) ────────────────────────────────
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 1) Vaste categorieën
        $catIds = [];
        $st = $pdo->prepare('INSERT INTO categorieen (code, name, type, sort_order) VALUES (:c,:n,:t,:o)');
        foreach (STRUCT_FIXED_CATEGORIES as $c) {
            $st->execute([
                ':c' => $c['code'], ':n' => $c['name'],
                ':t' => $c['type'], ':o' => $c['sort_order'],
            ]);
            $catIds[$c['code']] = (int)$pdo->lastInsertId();
        }

        // 2) App soorten
        $appIds = [];
        $st = $pdo->prepare('INSERT INTO applicatiesoorten (name, description, bron, sort_order) VALUES (:n,:d,:b,0)');
        foreach ($apps as $r) {
            $name = trim((string)$r['name']);
            $st->execute([
                ':n' => $name,
                ':d' => trim((string)($r['description'] ?? '')),
                ':b' => trim((string)($r['bron'] ?? '')) ?: null,
            ]);
            $appIds[$name] = (int)$pdo->lastInsertId();
        }

        // 3) Subcategorie-templates per categorie
        $st = $pdo->prepare(
            'INSERT INTO subcategorie_templates (categorie_id, applicatiesoort_id, name, bron, description, sort_order)
             VALUES (:c,:a,:n,:b,:d,0)'
        );
        foreach ($subs as $r) {
            $aId = ($r['categorie_code'] === 'FUNC' && $r['applicatiesoort_name'] !== '')
                ? $appIds[$r['applicatiesoort_name']]
                : null;
            $st->execute([
                ':c' => $catIds[$r['categorie_code']],
                ':a' => $aId,
                ':n' => $r['name'],
                ':b' => $r['bron'] !== '' ? $r['bron'] : null,
                ':d' => $r['description'] !== '' ? $r['description'] : null,
            ]);
        }

        // 4) DEMO-vragen
        $st = $pdo->prepare(
            'INSERT INTO demo_question_catalog (block, sort_order, text, active, created_at, updated_at)
             VALUES (:b,:o,:t,1,NOW(),NOW())'
        );
        $blockCounters = [];
        foreach ($demo as $r) {
            $blk = (int)$r['block'];
            $blockCounters[$blk] = ($blockCounters[$blk] ?? 0) + 10;
            $st->execute([
                ':b' => $blk,
                ':o' => $blockCounters[$blk],
                ':t' => trim((string)$r['text']),
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw new RuntimeException('Import mislukt: ' . $e->getMessage());
    }

    return [
        'cat'  => count(STRUCT_FIXED_CATEGORIES),
        'app'  => count($apps),
        'sub'  => count($subs),
        'demo' => count($demo),
    ];
}

function _struct_read_sheet($sheet, array $expectedCols): array {
    $rows = $sheet->toArray(null, true, true, false);
    if (!$rows) return [];
    $header = array_map(fn($v) => trim((string)$v), $rows[0]);
    foreach ($expectedCols as $i => $c) {
        if (($header[$i] ?? '') !== $c) {
            throw new RuntimeException("Tabblad '{$sheet->getTitle()}': kolom " . ($i + 1) . " moet '$c' heten (gevonden: '" . ($header[$i] ?? '') . "').");
        }
    }
    $out = [];
    for ($r = 1; $r < count($rows); $r++) {
        $row = $rows[$r];
        $allEmpty = true;
        foreach ($row as $v) { if (trim((string)$v) !== '') { $allEmpty = false; break; } }
        if ($allEmpty) continue;
        $assoc = [];
        foreach ($expectedCols as $i => $c) $assoc[$c] = $row[$i] ?? '';
        $out[] = $assoc;
    }
    return $out;
}
