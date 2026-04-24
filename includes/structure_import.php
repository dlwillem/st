<?php
/**
 * Import van de structuur-Excel (zie structure_export.php voor het format).
 * Strict, transactioneel, alleen op een lege structuur.
 */

if (!defined('APP_BOOT')) { http_response_code(403); exit('Forbidden'); }

use PhpOffice\PhpSpreadsheet\IOFactory;

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

    $required = ['Categorieen', 'Applicatiesoorten', 'Subcategorieen', 'DEMO-vragen'];
    foreach ($required as $t) {
        if ($ss->getSheetByName($t) === null) {
            throw new RuntimeException("Tabblad '$t' ontbreekt.");
        }
    }

    $cats = _struct_read_sheet($ss->getSheetByName('Categorieen'),       ['code','name','type','sort_order']);
    $apps = _struct_read_sheet($ss->getSheetByName('Applicatiesoorten'), ['label','description','sort_order']);
    $subs = _struct_read_sheet($ss->getSheetByName('Subcategorieen'),    ['categorie_code','applicatiesoort_label','name','sort_order']);
    $demo = _struct_read_sheet($ss->getSheetByName('DEMO-vragen'),       ['block','sort_order','text']);

    $validTypes = ['functional','non_functional','other'];
    // De applicatie heeft vaste top-level categorie-codes waaraan scoring,
    // wizard-stappen, Excel-exports en rapportage vasthangen. Eigen codes
    // zouden orphan-requirements opleveren — afkeuren bij import.
    $allowedCatCodes = ['FUNC','NFR','VEND','LIC','SUP'];
    $catByCode = [];
    foreach ($cats as $i => $r) {
        $code = trim((string)$r['code']);
        $name = trim((string)$r['name']);
        $type = trim((string)$r['type']);
        if ($code === '' || $name === '' || $type === '') {
            throw new RuntimeException('Categorieen rij ' . ($i + 2) . ': code, name en type zijn verplicht.');
        }
        if (!in_array($code, $allowedCatCodes, true)) {
            throw new RuntimeException(
                'Categorieen rij ' . ($i + 2) . ": code '$code' is niet toegestaan. "
                . 'Toegestane codes: ' . implode(', ', $allowedCatCodes)
                . '. Naam en sort_order mag je vrij kiezen, de code zelf niet.'
            );
        }
        if (!in_array($type, $validTypes, true)) {
            throw new RuntimeException('Categorieen rij ' . ($i + 2) . ": type '$type' ongeldig (functional/non_functional/other).");
        }
        if (isset($catByCode[$code])) {
            throw new RuntimeException("Categorieen: dubbele code '$code'.");
        }
        $catByCode[$code] = true;
    }
    $missing = array_diff($allowedCatCodes, array_keys($catByCode));
    if ($missing) {
        throw new RuntimeException(
            'Categorieen: alle vijf vaste codes zijn verplicht. Ontbreekt: '
            . implode(', ', $missing) . '.'
        );
    }

    $appByLabel = [];
    foreach ($apps as $i => $r) {
        $label = trim((string)$r['label']);
        if ($label === '') {
            throw new RuntimeException('Applicatiesoorten rij ' . ($i + 2) . ': label is verplicht.');
        }
        if (isset($appByLabel[$label])) {
            throw new RuntimeException("Applicatiesoorten: dubbel label '$label'.");
        }
        $appByLabel[$label] = true;
    }

    foreach ($subs as $i => $r) {
        $cc = trim((string)$r['categorie_code']);
        $al = trim((string)$r['applicatiesoort_label']);
        $nm = trim((string)$r['name']);
        if ($cc === '' || $nm === '') {
            throw new RuntimeException('Subcategorieen rij ' . ($i + 2) . ': categorie_code en name zijn verplicht.');
        }
        if (!isset($catByCode[$cc])) {
            throw new RuntimeException("Subcategorieen rij " . ($i + 2) . ": onbekende categorie_code '$cc'.");
        }
        if ($al !== '' && !isset($appByLabel[$al])) {
            throw new RuntimeException("Subcategorieen rij " . ($i + 2) . ": onbekend applicatiesoort_label '$al'.");
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
        $catIds = [];
        $st = $pdo->prepare('INSERT INTO categorieen (code, name, type, sort_order) VALUES (:c,:n,:t,:o)');
        foreach ($cats as $i => $r) {
            $st->execute([
                ':c' => trim((string)$r['code']),
                ':n' => trim((string)$r['name']),
                ':t' => trim((string)$r['type']),
                ':o' => (int)($r['sort_order'] ?? ($i + 1)),
            ]);
            $catIds[trim((string)$r['code'])] = (int)$pdo->lastInsertId();
        }

        $appIds = [];
        $st = $pdo->prepare('INSERT INTO applicatiesoorten (label, description, sort_order) VALUES (:l,:d,:o)');
        foreach ($apps as $i => $r) {
            $label = trim((string)$r['label']);
            $st->execute([
                ':l' => $label,
                ':d' => trim((string)($r['description'] ?? '')),
                ':o' => (int)($r['sort_order'] ?? ($i + 1)),
            ]);
            $appIds[$label] = (int)$pdo->lastInsertId();
        }

        $st = $pdo->prepare(
            'INSERT INTO subcategorie_templates (categorie_id, applicatiesoort_id, name, sort_order)
             VALUES (:c,:a,:n,:o)'
        );
        foreach ($subs as $i => $r) {
            $al = trim((string)$r['applicatiesoort_label']);
            $st->execute([
                ':c' => $catIds[trim((string)$r['categorie_code'])],
                ':a' => $al !== '' ? $appIds[$al] : null,
                ':n' => trim((string)$r['name']),
                ':o' => (int)($r['sort_order'] ?? ($i + 1)),
            ]);
        }

        $st = $pdo->prepare(
            'INSERT INTO demo_question_catalog (block, sort_order, text, active, created_at, updated_at)
             VALUES (:b,:o,:t,1,NOW(),NOW())'
        );
        foreach ($demo as $i => $r) {
            $st->execute([
                ':b' => (int)$r['block'],
                ':o' => (int)($r['sort_order'] ?? ($i + 1)),
                ':t' => trim((string)$r['text']),
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw new RuntimeException('Import mislukt: ' . $e->getMessage());
    }

    return [
        'cat'  => count($cats),
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
