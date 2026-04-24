<?php
/**
 * Excel-import/export voor requirements.
 *
 * Export : sheet "Requirements" met alle requirements van een traject.
 * Template: lege sheet + toelichtingsblad.
 * Import : idempotent, strict, transactioneel. Zie requirements_excel_import().
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/requirements.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/** Kolommen van zowel export als import-template (volgorde matters). */
const REQ_EXCEL_COLUMNS = [
    'code',          // leeg = nieuw requirement, ingevuld = update
    'hoofdcategorie',// naam, moet bestaan binnen traject
    'subcategorie',  // naam, moet bestaan binnen hoofdcategorie voor dit traject
    'titel',
    'omschrijving',
    'type',          // eis | wens | ko
];

/**
 * Download .xlsx met alle requirements van dit traject.
 *
 * Gestileerd in dezelfde DKG-huisstijl als de leverancier-export: één tabblad
 * per hoofdcategorie (FUNC/NFR/VEND/LIC/SUP) met een roze banner en kolommen
 * Nr, Domein, Titel, Omschrijving, MoSCoW. Géén antwoord-kolommen en géén
 * instructies-tab (dit bestand is voor intern gebruik).
 */
function requirements_excel_export(int $trajectId, string $filename): void {
    $traject = db_one('SELECT name FROM trajecten WHERE id = :id', [':id' => $trajectId]);
    if (!$traject) { http_response_code(404); exit('Traject niet gevonden.'); }

    $reqs = db_all(
        'SELECT r.id, r.code, r.title, r.description, r.type,
                s.name AS sub_name, s.sort_order AS sub_order,
                c.name AS cat_name, c.code AS cat_code, c.sort_order AS cat_order,
                r.sort_order AS req_order
           FROM requirements r
           JOIN subcategorieen s ON s.id = r.subcategorie_id
           JOIN categorieen    c ON c.id = s.categorie_id
          WHERE r.traject_id = :t
          ORDER BY c.sort_order, s.sort_order, r.sort_order, r.id',
        [':t' => $trajectId]
    );
    $byScope = [];
    foreach ($reqs as $r) $byScope[$r['cat_code']][] = $r;

    // Subcategorieën per scope (ook voor lege scopes)
    $subRows = db_all(
        'SELECT s.name, c.code AS cat_code
           FROM subcategorieen s
           JOIN categorieen c ON c.id = s.categorie_id
          WHERE s.traject_id = :t
          ORDER BY c.sort_order, s.sort_order, s.id',
        [':t' => $trajectId]
    );
    $subsByScope = [];
    foreach ($subRows as $sr) $subsByScope[$sr['cat_code']][] = $sr['name'];

    $scopes = ['FUNC', 'NFR', 'VEND', 'LIC', 'SUP'];
    $titles = [
        'FUNC' => 'DKG Functional Requirements',
        'NFR'  => 'DKG Non Functional Requirements',
        'VEND' => 'DKG Vendor Requirements',
        'LIC'  => 'DKG Licence Requirements',
        'SUP'  => 'DKG Support Requirements',
    ];
    $moscow = ['eis' => 'Must', 'wens' => 'Should', 'ko' => 'Knock-out'];

    $ss = new Spreadsheet();
    $ss->removeSheetByIndex(0);

    foreach ($scopes as $scope) {
        $rows  = $byScope[$scope] ?? [];
        $subs  = $subsByScope[$scope] ?? [];
        $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($ss, $scope);
        $ss->addSheet($sheet);
        requirements_excel_build_sheet($sheet, $scope, $titles[$scope], $rows, $moscow, $subs);
    }

    $ss->setActiveSheetIndex(0);
    requirements_excel_send($ss, $filename);
}

/**
 * Bouwt één scope-tabblad (roze banner + 5 kolommen, geen antwoord-kolommen).
 */
function requirements_excel_build_sheet(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s,
    string $scope,
    string $title,
    array $reqs,
    array $moscow,
    array $subNames = []
): void {
    $pink       = 'B33771';
    $white      = 'FFFFFF';
    $borderGray = 'BBBBBB';

    $headers = ['Nr', 'Domein', 'Titel', 'Omschrijving', 'MoSCoW'];
    $letters = ['A', 'B', 'C', 'D', 'E'];
    $first   = $letters[0];
    $last    = $letters[count($letters) - 1];

    // Rij 1: banner
    $s->setCellValue($first . '1', $title);
    $s->mergeCells($first . '1:' . $last . '1');
    $s->getStyle($first . '1:' . $last . '1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => $white]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $pink]],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical'   => Alignment::VERTICAL_CENTER,
        ],
    ]);
    $s->getRowDimension(1)->setRowHeight(28);

    // Rij 2: kolomkoppen
    foreach ($headers as $i => $h) $s->setCellValue($letters[$i] . '2', $h);
    $s->getStyle($first . '2:' . $last . '2')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => $white]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $pink]],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical'   => Alignment::VERTICAL_CENTER,
            'wrapText'   => true,
        ],
    ]);
    $s->getRowDimension(2)->setRowHeight(32);

    $widths = [
        $letters[0] => 12,  // nr
        $letters[1] => 28,  // domein
        $letters[2] => 36,  // titel
        $letters[3] => 80,  // omschrijving
        $letters[4] => 14,  // moscow
    ];
    foreach ($widths as $col => $w) $s->getColumnDimension($col)->setWidth($w);

    if (!$reqs) {
        $msg = 'Geen requirements gedefinieerd voor deze scope in dit traject.';
        if ($subNames) {
            $msg .= "\nThema's aangemaakt: " . implode(' · ', $subNames);
        }
        $s->setCellValue($first . '3', $msg);
        $s->mergeCells($first . '3:' . $last . '3');
        $s->getStyle($first . '3:' . $last . '3')->applyFromArray([
            'font' => ['italic' => true, 'color' => ['rgb' => '666666']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F5F5']],
        ]);
        $s->getRowDimension(3)->setRowHeight($subNames ? 48 : 28);
        $s->freezePane('A3');
        return;
    }

    $rn = 3;
    foreach ($reqs as $req) {
        $vals = [
            $req['code'],
            $req['cat_name'] . ' → ' . $req['sub_name'],
            $req['title'],
            (string)($req['description'] ?? ''),
            $moscow[$req['type']] ?? $req['type'],
        ];
        foreach ($vals as $i => $v) {
            $s->setCellValueExplicit(
                $letters[$i] . $rn,
                (string)$v,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );
        }
        $rn++;
    }
    $lastRow = $rn - 1;

    $range = $first . '3:' . $last . $lastRow;
    $s->getStyle($range)->applyFromArray([
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $borderGray]],
        ],
        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
    ]);
    // Nr en MoSCoW gecentreerd
    $s->getStyle($letters[0] . '3:' . $letters[0] . $lastRow)
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $s->getStyle($letters[4] . '3:' . $letters[4] . $lastRow)
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $s->freezePane('A3');
}

/**
 * Download .xlsx template (lege requirements-sheet + uitleg).
 */
function requirements_excel_template(int $trajectId, string $filename): void {
    $ss = new Spreadsheet();

    // Sheet 1: Requirements
    $s1 = $ss->getActiveSheet();
    $s1->setTitle('Requirements');
    $s1->fromArray(REQ_EXCEL_COLUMNS, null, 'A1');
    $s1->getStyle('A1:F1')->getFont()->setBold(true);
    foreach (range('A', 'F') as $c) $s1->getColumnDimension($c)->setAutoSize(true);

    // Sheet 2: Toelichting (namen van hoofdcategorieën + subcategorieën)
    $s2 = $ss->createSheet();
    $s2->setTitle('Toelichting');
    $lines = [
        ['Kolom', 'Uitleg'],
        ['code',           'Leeg laten voor een NIEUW requirement. Ingevuld = update op bestaande code.'],
        ['hoofdcategorie', 'Naam exact zoals in dit traject. Zie tabblad hieronder voor geldige waarden.'],
        ['subcategorie',   'Naam binnen de hoofdcategorie.'],
        ['titel',          'Vereist. Korte titel van het requirement.'],
        ['omschrijving',   'Optioneel.'],
        ['type',           'eis | wens | ko'],
        ['', ''],
        ['Beschikbare hoofdcategorieën / subcategorieën in dit traject:', ''],
    ];
    $s2->fromArray($lines, null, 'A1');
    $s2->getStyle('A1:B1')->getFont()->setBold(true);

    $row = count($lines) + 1;
    $subs = requirement_subcats_for_traject($trajectId);
    foreach ($subs as $sub) {
        $s2->fromArray([$sub['cat_name'], $sub['name']], null, 'A' . $row);
        $row++;
    }
    foreach (['A', 'B'] as $c) $s2->getColumnDimension($c)->setAutoSize(true);

    requirements_excel_send($ss, $filename);
}

function requirements_excel_send(Spreadsheet $ss, string $filename): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $w = new XlsxWriter($ss);
    $w->save('php://output');
    exit;
}

/**
 * Importeer een geüploade .xlsx strict en all-or-nothing.
 * Validatie:
 *   - Hoofdcategorie-naam moet in dit traject bestaan.
 *   - Subcategorie-naam moet binnen die hoofdcat in dit traject bestaan.
 *   - type ∈ {eis, wens, ko}.
 *   - code ingevuld → moet bestaan in dit traject (update).
 *   - code leeg → nieuw requirement, code wordt server-side toegekend.
 *   - Ontbrekende titel = fout.
 *
 * Bij ≥1 fout wordt er níéts gemuteerd.
 *
 * @return array{ok:bool, created:int, updated:int, errors: string[], rows: int}
 */
function requirements_excel_import(int $trajectId, string $path): array {
    $result = ['ok' => false, 'created' => 0, 'updated' => 0, 'errors' => [], 'rows' => 0];

    try {
        $reader = IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) $reader->setReadDataOnly(true);
        if (method_exists($reader, 'setReadEmptyCells')) $reader->setReadEmptyCells(false);
        $ss = $reader->load($path);
    } catch (Throwable $e) {
        $result['errors'][] = 'Kon bestand niet openen: ' . $e->getMessage();
        return $result;
    }

    // Altijd eerste sheet.
    $sheet = $ss->getSheet(0);
    $rows  = $sheet->toArray(null, true, true, true);
    if (count($rows) < 2) {
        $result['errors'][] = 'Bestand bevat geen datarijen.';
        return $result;
    }

    // Headers (rij 1) → kolomletters
    $headerRow = array_map(fn($v) => mb_strtolower(trim((string)$v)), $rows[1]);
    $colByName = [];
    foreach ($headerRow as $letter => $name) $colByName[$name] = $letter;
    foreach (REQ_EXCEL_COLUMNS as $required) {
        if (!isset($colByName[$required])) {
            $result['errors'][] = "Verplichte kolom ontbreekt: $required";
        }
    }
    if ($result['errors']) return $result;

    // Subcategorieën ophalen: (cat_name, sub_name) → sub_id
    $subRows = requirement_subcats_for_traject($trajectId);
    $subLookup = [];
    foreach ($subRows as $s) {
        $key = mb_strtolower($s['cat_name']) . '||' . mb_strtolower($s['name']);
        $subLookup[$key] = [
            'id'      => (int)$s['id'],
            'cat_id'  => (int)$s['cat_id'],
            'cat_name'=> $s['cat_name'],
        ];
    }

    // Bestaande codes in dit traject
    $codeRows = db_all(
        'SELECT id, code, subcategorie_id FROM requirements WHERE traject_id = :t',
        [':t' => $trajectId]
    );
    $existingByCode = [];
    foreach ($codeRows as $r) $existingByCode[mb_strtoupper($r['code'])] = $r;

    // Validatie + plan opbouwen
    $plan = []; // ['create'=>[data], 'update'=>[data,id]]
    for ($rn = 2; $rn <= count($rows) + 1; $rn++) {
        if (!isset($rows[$rn])) continue;
        $raw = $rows[$rn];
        // Skip volledig lege rijen
        $allEmpty = true;
        foreach ($raw as $v) {
            if (trim((string)$v) !== '') { $allEmpty = false; break; }
        }
        if ($allEmpty) continue;
        $result['rows']++;

        $get = function (string $col) use ($raw, $colByName) {
            $letter = $colByName[$col] ?? null;
            if (!$letter) return '';
            return trim((string)($raw[$letter] ?? ''));
        };

        $code  = $get('code');
        $cat   = $get('hoofdcategorie');
        $sub   = $get('subcategorie');
        $title = $get('titel');
        $desc  = $get('omschrijving');
        $type  = mb_strtolower($get('type'));

        $rowErr = [];
        if ($title === '') $rowErr[] = 'titel leeg';
        if (!in_array($type, REQUIREMENT_TYPES, true)) $rowErr[] = "type '$type' ongeldig";
        $key = mb_strtolower($cat) . '||' . mb_strtolower($sub);
        $subInfo = $subLookup[$key] ?? null;
        if (!$subInfo) $rowErr[] = "subcategorie '$cat / $sub' onbekend";

        if ($code !== '' && !isset($existingByCode[mb_strtoupper($code)])) {
            $rowErr[] = "code '$code' bestaat niet in dit traject";
        }

        if ($rowErr) {
            $result['errors'][] = "Rij $rn: " . implode(', ', $rowErr);
            continue;
        }

        if ($code === '') {
            $plan[] = [
                'op'    => 'create',
                'sub'   => $subInfo['id'],
                'title' => $title,
                'desc'  => $desc,
                'type'  => $type,
            ];
        } else {
            $plan[] = [
                'op'    => 'update',
                'id'    => (int)$existingByCode[mb_strtoupper($code)]['id'],
                'sub'   => $subInfo['id'],
                'title' => $title,
                'desc'  => $desc,
                'type'  => $type,
            ];
        }
    }

    if ($result['errors']) return $result;

    // Uitvoeren in één transactie
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
