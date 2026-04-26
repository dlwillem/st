<?php
/**
 * Excel-import/export voor requirements.
 *
 * Format (sinds redesign): één tabblad per scope (FUNC/NFR/VEND/IMPL/SUP/LIC).
 * De hoofdcategorie wordt afgeleid uit de tabbladnaam — net als bij de
 * structuur-export. Export en template hebben hetzelfde format, dus de
 * download is direct round-trip-importeerbaar.
 *
 * Kolommen per scope:
 *   FUNC :  code, app_soort, subcategorie, titel, omschrijving, type
 *   NFR  :  code, subcategorie, titel, omschrijving, type
 *   VEND :  code, subcategorie, titel, omschrijving, type
 *   IMPL :  code, subcategorie, titel, omschrijving, type
 *   SUP  :  code, subcategorie, titel, omschrijving, type
 *   LIC  :  code, subcategorie, titel, omschrijving, type
 *
 * code leeg → nieuw requirement; code ingevuld → update op bestaande.
 * type ∈ {eis, wens, ko}.
 */

if (!defined('APP_BOOT')) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/requirements.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

const REQ_EXCEL_SCOPES = ['FUNC', 'NFR', 'VEND', 'IMPL', 'SUP', 'LIC'];

const REQ_EXCEL_COLUMNS_FUNC  = ['code', 'app_soort', 'subcategorie', 'titel', 'omschrijving', 'type'];
const REQ_EXCEL_COLUMNS_OTHER = ['code', 'subcategorie', 'titel', 'omschrijving', 'type'];

function _req_excel_cols_for(string $scope): array {
    return $scope === 'FUNC' ? REQ_EXCEL_COLUMNS_FUNC : REQ_EXCEL_COLUMNS_OTHER;
}

/**
 * Download .xlsx met alle requirements van dit traject — round-trip-importeerbaar.
 */
function requirements_excel_export(int $trajectId, string $filename): void {
    $traject = db_one('SELECT name FROM trajecten WHERE id = :id', [':id' => $trajectId]);
    if (!$traject) { http_response_code(404); exit('Traject niet gevonden.'); }

    $reqs = db_all(
        'SELECT r.code, r.title, r.description, r.type,
                s.name AS sub_name,
                a.name AS app_name,
                c.code AS cat_code, c.sort_order AS cat_order,
                s.sort_order AS sub_order, r.sort_order AS req_order
           FROM requirements r
           JOIN subcategorieen s ON s.id = r.subcategorie_id
           JOIN categorieen    c ON c.id = s.categorie_id
           LEFT JOIN applicatiesoorten a ON a.id = s.applicatiesoort_id
          WHERE r.traject_id = :t
          ORDER BY c.sort_order, a.name, s.sort_order, r.sort_order, r.id',
        [':t' => $trajectId]
    );
    $byScope = [];
    foreach ($reqs as $r) $byScope[$r['cat_code']][] = $r;

    $ss = new Spreadsheet();
    $ss->removeSheetByIndex(0);
    foreach (REQ_EXCEL_SCOPES as $scope) {
        $sheet = $ss->createSheet();
        $sheet->setTitle($scope);
        _req_excel_build_sheet($sheet, $scope, $byScope[$scope] ?? []);
    }
    $ss->setActiveSheetIndex(0);
    requirements_excel_send($ss, $filename);
}

/**
 * Download .xlsx template (lege scope-sheets + toelichting).
 */
function requirements_excel_template(int $trajectId, string $filename): void {
    $ss = new Spreadsheet();
    $ss->removeSheetByIndex(0);

    // Toelichting-tab
    $info = $ss->createSheet();
    $info->setTitle('Toelichting');
    $info->fromArray([
        ['Requirements-template'],
        [''],
        ['Eén tabblad per scope. Vul de kolommen in zoals aangegeven; tabbladnamen, kolomvolgorde en kolomnamen niet wijzigen.'],
        [''],
        ['code          — leeg laten voor een NIEUW requirement; ingevuld = update op bestaande code in dit traject.'],
        ['app_soort     — alleen FUNC: naam van de App soort waaronder de subcategorie hangt.'],
        ['subcategorie  — naam exact zoals in dit traject (FUNC: app service onder de gekozen App soort).'],
        ['titel         — verplicht.'],
        ['omschrijving  — optioneel.'],
        ['type          — eis | wens | ko'],
        [''],
        ['Beschikbare subcategorieën in dit traject (per scope):'],
    ], null, 'A1');
    $info->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $info->getColumnDimension('A')->setWidth(28);
    $info->getColumnDimension('B')->setWidth(36);
    $info->getColumnDimension('C')->setWidth(60);

    $row = 14;
    $info->fromArray([['scope', 'app_soort', 'subcategorie']], null, 'A' . $row);
    $info->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
    $row++;
    $subs = requirement_subcats_for_traject_with_app($trajectId);
    foreach ($subs as $s) {
        $info->fromArray([[$s['cat_code'], (string)($s['app_name'] ?? ''), $s['name']]], null, 'A' . $row++);
    }

    foreach (REQ_EXCEL_SCOPES as $scope) {
        $sheet = $ss->createSheet();
        $sheet->setTitle($scope);
        _req_excel_build_sheet($sheet, $scope, []);
    }

    $ss->setActiveSheetIndex(0);
    requirements_excel_send($ss, $filename);
}

/** Bouw één scope-tabblad: roze headerrij + (optioneel) datarijen. */
function _req_excel_build_sheet(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
    string $scope,
    array $rows
): void {
    $cols = _req_excel_cols_for($scope);

    $sheet->fromArray([$cols], null, 'A1');
    $lastCol = _req_col_letter(count($cols));
    $sheet->getStyle('A1:' . $lastCol . '1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EC4899']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
    ]);

    $widths = [
        'code'          => 12,
        'app_soort'     => 26,
        'subcategorie'  => 28,
        'titel'         => 36,
        'omschrijving'  => 60,
        'type'          => 10,
    ];
    foreach ($cols as $i => $name) {
        $sheet->getColumnDimensionByColumn($i + 1)->setWidth($widths[$name] ?? 20);
    }
    $sheet->freezePane('A2');

    $moscow = ['eis' => 'eis', 'wens' => 'wens', 'ko' => 'ko'];
    $rn = 2;
    foreach ($rows as $r) {
        $vals = [];
        foreach ($cols as $name) {
            switch ($name) {
                case 'code':         $vals[] = (string)$r['code']; break;
                case 'app_soort':    $vals[] = (string)($r['app_name'] ?? ''); break;
                case 'subcategorie': $vals[] = (string)$r['sub_name']; break;
                case 'titel':        $vals[] = (string)$r['title']; break;
                case 'omschrijving': $vals[] = (string)($r['description'] ?? ''); break;
                case 'type':         $vals[] = $moscow[$r['type']] ?? $r['type']; break;
            }
        }
        foreach ($vals as $i => $v) {
            $sheet->setCellValueExplicit(
                _req_col_letter($i + 1) . $rn,
                (string)$v,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );
        }
        $rn++;
    }
}

/** Subcats met app-soort-info voor de toelichtings-tab + import-lookup. */
function requirement_subcats_for_traject_with_app(int $trajectId): array {
    return db_all(
        'SELECT s.id, s.name, s.sort_order,
                c.id AS cat_id, c.code AS cat_code, c.name AS cat_name, c.sort_order AS cat_order,
                a.name AS app_name
           FROM subcategorieen s
           JOIN categorieen c ON c.id = s.categorie_id
           LEFT JOIN applicatiesoorten a ON a.id = s.applicatiesoort_id
          WHERE s.traject_id = :t
          ORDER BY c.sort_order, a.name, s.sort_order, s.id',
        [':t' => $trajectId]
    );
}

function requirements_excel_send(Spreadsheet $ss, string $filename): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    (new XlsxWriter($ss))->save('php://output');
    exit;
}

function _req_col_letter(int $n): string {
    $s = '';
    while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); }
    return $s;
}

/**
 * Strict, transactioneel, all-or-nothing import.
 * Leest alle 6 scope-tabbladen; hoofdcategorie wordt afgeleid uit tabnaam.
 *
 * @return array{ok:bool, created:int, updated:int, errors: string[], rows: int}
 */
function requirements_excel_import(int $trajectId, string $path): array {
    $result = ['ok' => false, 'created' => 0, 'updated' => 0, 'errors' => [], 'rows' => 0];

    try {
        $reader = IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly'))   $reader->setReadDataOnly(true);
        if (method_exists($reader, 'setReadEmptyCells')) $reader->setReadEmptyCells(false);
        $ss = $reader->load($path);
    } catch (Throwable $e) {
        $result['errors'][] = 'Kon bestand niet openen: ' . $e->getMessage();
        return $result;
    }

    foreach (REQ_EXCEL_SCOPES as $scope) {
        if ($ss->getSheetByName($scope) === null) {
            $result['errors'][] = "Tabblad '$scope' ontbreekt.";
        }
    }
    if ($result['errors']) return $result;

    // Lookup-tabellen
    $subRows = requirement_subcats_for_traject_with_app($trajectId);
    // Voor FUNC: (app_soort, subcategorie) → sub_id
    // Voor andere scopes: (cat_code, subcategorie) → sub_id
    $funcLookup = []; // app||sub → id
    $otherLookup = []; // catcode||sub → id
    foreach ($subRows as $s) {
        if ($s['cat_code'] === 'FUNC') {
            $key = mb_strtolower((string)($s['app_name'] ?? '')) . '||' . mb_strtolower($s['name']);
            $funcLookup[$key] = (int)$s['id'];
        } else {
            $key = $s['cat_code'] . '||' . mb_strtolower($s['name']);
            $otherLookup[$key] = (int)$s['id'];
        }
    }

    // Bestaande codes in dit traject
    $codeRows = db_all(
        'SELECT id, code FROM requirements WHERE traject_id = :t',
        [':t' => $trajectId]
    );
    $existingByCode = [];
    foreach ($codeRows as $r) $existingByCode[mb_strtoupper($r['code'])] = (int)$r['id'];

    // Per scope-tab: header valideren + rijen verzamelen
    $plan = [];
    foreach (REQ_EXCEL_SCOPES as $scope) {
        $cols  = _req_excel_cols_for($scope);
        $sheet = $ss->getSheetByName($scope);
        $rows  = $sheet->toArray(null, true, true, false);
        if (!$rows) continue;

        $header = array_map(fn($v) => mb_strtolower(trim((string)$v)), $rows[0]);
        foreach ($cols as $i => $expected) {
            if (($header[$i] ?? '') !== $expected) {
                $result['errors'][] = "Tab '$scope': kolom " . ($i + 1)
                    . " moet '$expected' heten (gevonden: '" . ($header[$i] ?? '') . "').";
            }
        }

        for ($idx = 1; $idx < count($rows); $idx++) {
            $raw = $rows[$idx];
            $allEmpty = true;
            foreach ($raw as $v) { if (trim((string)$v) !== '') { $allEmpty = false; break; } }
            if ($allEmpty) continue;

            $rowNo = $idx + 1;
            $assoc = [];
            foreach ($cols as $i => $name) $assoc[$name] = trim((string)($raw[$i] ?? ''));
            $result['rows']++;

            $code  = $assoc['code'];
            $sub   = $assoc['subcategorie'];
            $title = $assoc['titel'];
            $desc  = $assoc['omschrijving'];
            $type  = mb_strtolower($assoc['type']);
            $app   = $assoc['app_soort'] ?? '';

            $rowErr = [];
            if ($title === '') $rowErr[] = 'titel leeg';
            if (!in_array($type, REQUIREMENT_TYPES, true)) $rowErr[] = "type '$type' ongeldig";

            $subId = null;
            if ($scope === 'FUNC') {
                if ($app === '') $rowErr[] = 'app_soort leeg';
                if ($sub === '') $rowErr[] = 'subcategorie leeg';
                if ($app !== '' && $sub !== '') {
                    $key = mb_strtolower($app) . '||' . mb_strtolower($sub);
                    $subId = $funcLookup[$key] ?? null;
                    if ($subId === null) {
                        $rowErr[] = "onbekende combinatie app_soort '$app' + subcategorie '$sub'";
                    }
                }
            } else {
                if ($sub === '') $rowErr[] = 'subcategorie leeg';
                if ($sub !== '') {
                    $key = $scope . '||' . mb_strtolower($sub);
                    $subId = $otherLookup[$key] ?? null;
                    if ($subId === null) {
                        $rowErr[] = "subcategorie '$sub' onbekend binnen $scope";
                    }
                }
            }

            $reqId = null;
            if ($code !== '') {
                $reqId = $existingByCode[mb_strtoupper($code)] ?? null;
                if ($reqId === null) $rowErr[] = "code '$code' bestaat niet in dit traject";
            }

            if ($rowErr) {
                $result['errors'][] = "Tab '$scope' rij $rowNo: " . implode(', ', $rowErr);
                continue;
            }

            $plan[] = $reqId === null
                ? ['op' => 'create', 'sub' => $subId, 'title' => $title, 'desc' => $desc, 'type' => $type]
                : ['op' => 'update', 'id' => $reqId, 'sub' => $subId, 'title' => $title, 'desc' => $desc, 'type' => $type];
        }
    }

    if ($result['errors']) return $result;

    db_transaction(function () use ($plan, $trajectId, &$result) {
        foreach ($plan as $item) {
            if ($item['op'] === 'create') {
                requirement_create($trajectId, [
                    'subcategorie_id' => $item['sub'],
                    'title'           => $item['title'],
                    'description'     => $item['desc'],
                    'type'            => $item['type'],
                ]);
                $result['created']++;
            } else {
                requirement_update($item['id'], $trajectId, [
                    'subcategorie_id' => $item['sub'],
                    'title'           => $item['title'],
                    'description'     => $item['desc'],
                    'type'            => $item['type'],
                ]);
                $result['updated']++;
            }
        }
    });
    $result['ok'] = true;
    return $result;
}
