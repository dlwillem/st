<?php
/**
 * Export van de applicatie-structuur (applicatiesoorten,
 * subcategorie-templates per hoofdcategorie, DEMO-catalog) naar één .xlsx.
 *
 * Modes:
 *   - 'current'  : met alle huidige data
 *   - 'template' : lege sheets, alleen headers + instructies
 *
 * De zes hoofdcategorieën (FUNC, NFR, VEND, IMPL, SUP, LIC) zijn vast in de
 * code en worden niet via Excel beheerd. Per categorie is er één tabblad met
 * de bijbehorende subcategorieën (= "app services" voor FUNC, "domeinen"
 * voor NFR, "thema's" voor VEND/IMPL, etc.).
 *
 * Kolomvolgorde is tevens het import-contract (zie structure_import.php).
 */

if (!defined('APP_BOOT')) { http_response_code(403); exit('Forbidden'); }

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

const STRUCT_SHEETS = [
    'App soorten'  => ['name', 'description', 'bron'],
    'App services' => ['applicatiesoort_name', 'name', 'bron', 'description'],
    'NFR'          => ['name', 'bron', 'description'],
    'VEND'         => ['name', 'bron', 'description'],
    'IMPL'         => ['name', 'bron', 'description'],
    'SUP'          => ['name', 'bron', 'description'],
    'LIC'          => ['name', 'bron', 'description'],
    'DEMO-vragen'  => ['block', 'text'],
];

/** Mapping van tabbladnaam → hoofdcategorie-code voor subcategorie-tabs. */
const STRUCT_SUBCAT_SHEETS = [
    'App services' => 'FUNC',
    'NFR'          => 'NFR',
    'VEND'         => 'VEND',
    'IMPL'         => 'IMPL',
    'SUP'          => 'SUP',
    'LIC'          => 'LIC',
];

function structure_export_xlsx(string $mode, string $filename): void {
    $ss = new Spreadsheet();
    $ss->removeSheetByIndex(0);

    // ── Instructies-tab ────────────────────────────────────────────
    $info = $ss->createSheet();
    $info->setTitle('Instructies');
    $info->fromArray([
        ['Structuur-template'],
        [''],
        ['Vul onderstaande tabbladen. Tabbladnamen, kolomvolgorde en kolomnamen niet wijzigen.'],
        [''],
        ['De zes hoofdcategorieën (FUNC, NFR, VEND, IMPL, SUP, LIC) staan vast in de app'],
        ['en worden automatisch aangemaakt met hun standaardnamen.'],
        [''],
        ['App soorten   — name (uniek), description, bron'],
        ['App services  — applicatiesoort_name (verplicht, verwijst naar App soorten), name, bron, description'],
        ['NFR / VEND /  — name, bron, description (één tabblad per categorie; subcategorieën hangen direct'],
        ['IMPL / SUP /    onder de categorie, geen koppeling met App soorten)'],
        ['LIC'],
        ['DEMO-vragen   — block (1..n), text'],
        [''],
        ['Import overschrijft de huidige structuur niet; upload alleen op een lege structuur (gebruik eerst Wipe).'],
    ], null, 'A1');
    $info->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $info->getColumnDimension('A')->setWidth(110);

    // ── Data-sheets ────────────────────────────────────────────────
    foreach (STRUCT_SHEETS as $title => $cols) {
        $sh = $ss->createSheet();
        $sh->setTitle($title);
        $sh->fromArray([$cols], null, 'A1');
        $sh->getStyle('A1:' . _col_letter(count($cols)) . '1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EC4899']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        foreach ($cols as $i => $_) $sh->getColumnDimensionByColumn($i + 1)->setWidth(28);
        $sh->freezePane('A2');
    }

    if ($mode === 'current') {
        _struct_fill_current($ss);
    }

    $ss->setActiveSheetIndex(0);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    (new XlsxWriter($ss))->save('php://output');
    exit;
}

function _struct_fill_current(Spreadsheet $ss): void {
    $apps = db_all('SELECT name, description, bron FROM applicatiesoorten ORDER BY name');
    _struct_write_rows($ss->getSheetByName('App soorten'), $apps, ['name','description','bron']);

    foreach (STRUCT_SUBCAT_SHEETS as $sheetName => $catCode) {
        if ($catCode === 'FUNC') {
            $rows = db_all(
                'SELECT a.name AS applicatiesoort_name, t.name, t.bron, t.description
                   FROM subcategorie_templates t
                   JOIN categorieen c ON c.id = t.categorie_id
                   LEFT JOIN applicatiesoorten a ON a.id = t.applicatiesoort_id
                  WHERE c.code = :c
                  ORDER BY a.name, t.name, t.id',
                [':c' => $catCode]
            );
            _struct_write_rows($ss->getSheetByName($sheetName), $rows, ['applicatiesoort_name','name','bron','description']);
        } else {
            $rows = db_all(
                'SELECT t.name, t.bron, t.description
                   FROM subcategorie_templates t
                   JOIN categorieen c ON c.id = t.categorie_id
                  WHERE c.code = :c
                  ORDER BY t.name, t.id',
                [':c' => $catCode]
            );
            _struct_write_rows($ss->getSheetByName($sheetName), $rows, ['name','bron','description']);
        }
    }

    $demo = db_all('SELECT block, text FROM demo_question_catalog WHERE active = 1 ORDER BY block, sort_order, id');
    _struct_write_rows($ss->getSheetByName('DEMO-vragen'), $demo, ['block','text']);
}

function _struct_write_rows($sheet, array $rows, array $cols): void {
    $r = 2;
    foreach ($rows as $row) {
        $out = [];
        foreach ($cols as $c) $out[] = $row[$c] ?? '';
        $sheet->fromArray([$out], null, 'A' . $r++);
    }
}

function _col_letter(int $n): string {
    $s = '';
    while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); }
    return $s;
}

/**
 * Wipe-check: mag de structuur weg?
 * Gate: 0 requirements én 0 leveranciers in de hele app.
 */
function structure_wipe_allowed(): bool {
    $req = (int)db()->query('SELECT COUNT(*) FROM requirements')->fetchColumn();
    $lev = (int)db()->query('SELECT COUNT(*) FROM leveranciers')->fetchColumn();
    return $req === 0 && $lev === 0;
}

/**
 * Verwijdert de hele structuur. Veronderstelt dat structure_wipe_allowed()
 * de caller al heeft bewaakt; deze functie zelf controleert ook nogmaals.
 *
 * Wipe-scope: weights → subcategorieen → subcategorie_templates
 *             → demo_question_catalog → categorieen → applicatiesoorten.
 */
function structure_wipe(): void {
    if (!structure_wipe_allowed()) {
        throw new RuntimeException('Wipe niet toegestaan zolang er requirements of leveranciers bestaan.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM weights');
        $pdo->exec('DELETE FROM subcategorieen');
        $pdo->exec('DELETE FROM subcategorie_templates');
        $pdo->exec('DELETE FROM demo_question_catalog');
        $pdo->exec('DELETE FROM categorieen');
        $pdo->exec('DELETE FROM applicatiesoorten');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
