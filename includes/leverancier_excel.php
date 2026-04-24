<?php
/**
 * Excel-export + import voor leverancier-antwoorden per requirement.
 *
 * Export: één .xlsx per leverancier, één tab per hoofdcategorie
 * (FUNC/NFR/VEND/LIC/SUP). Layout conform DKG-huisstijl: pink banner
 * links (traject-info), groene banner rechts (in te vullen door leverancier).
 *
 * Kolommen: code, domein (hoofdcat/subcat), titel, omschrijving, MoSCoW,
 *           Standaard Ja/Nee, Toelichting.
 *
 * Import: strict, all-or-nothing. Matchen gebeurt op requirement-code.
 *         "Standaard Ja/Nee" mapt intern naar answer_choice:
 *              Ja  → volledig
 *              Nee → niet
 *         Legacy waarden (volledig/deels/niet/nvt) blijven werken.
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

const LEV_ANSWER_CHOICES = ['volledig', 'deels', 'niet', 'nvt'];
const LEV_EXCEL_SCOPES   = ['FUNC', 'NFR', 'VEND', 'LIC', 'SUP'];
const LEV_EXCEL_LANGS    = ['nl', 'en'];

/** Vertalingen per taal. */
const LEV_EXCEL_I18N = [
    'nl' => [
        'scope_titles' => [
            'FUNC' => 'DKG Functional Requirements',
            'NFR'  => 'DKG Non Functional Requirements',
            'VEND' => 'DKG Vendor Requirements',
            'LIC'  => 'DKG Licence Requirements',
            'SUP'  => 'DKG Support Requirements',
        ],
        'headers' => [
            'nr'           => 'Nr',
            'domein'       => 'Domein',
            'titel'        => 'Titel',
            'omschrijving' => 'Omschrijving',
            'moscow'       => 'MoSCoW',
            'standaard'    => 'Standaard Ja / Nee / Deels',
            'toelichting'  => 'Toelichting',
        ],
        'banner_right'   => 'In te vullen door Leverancier',
        'moscow'         => ['eis' => 'Must', 'wens' => 'Should', 'ko' => 'Knock-out'],
        'yes'            => 'Ja',
        'no'             => 'Nee',
        'partial'        => 'Deels',
        'dv_err_title'   => 'Ongeldige waarde',
        'dv_err'         => 'Kies Ja, Nee of Deels.',
        'dv_prompt_title'=> 'Standaard Ja / Nee / Deels',
        'dv_prompt'      => 'Voldoet de standaardoplossing volledig (Ja), niet (Nee) of gedeeltelijk (Deels)?',
        'instructies_tab'=> 'Instructies',
        'instructies'    => [
            ['Invulinstructies'],
            [''],
            ['Traject',      '{traject}'],
            ['Leverancier',  '{lev}'],
            ['Geëxporteerd', '{date}'],
            [''],
            ['Per hoofdcategorie staat een aparte tab (FUNC, NFR, VEND, LIC, SUP).'],
            [''],
            ['Vul per rij de kolom "Standaard Ja / Nee / Deels" in met één van:'],
            ['  Ja     — de standaardoplossing voldoet volledig aan dit requirement'],
            ['  Nee    — de standaardoplossing voldoet niet (zonder maatwerk)'],
            ['  Deels  — de standaardoplossing voldoet gedeeltelijk — leg uit in de Toelichting'],
            [''],
            ['"Toelichting" is optioneel — gebruik dit om Ja/Nee te onderbouwen,'],
            ['bv. onder welke voorwaarden, welke versie/module vereist is, etc.'],
            [''],
            ['AUTOMATISCHE SCORING — hoe wij je antwoorden verwerken:'],
            ['  · Ja zonder toelichting   → hoogste score. We nemen aan dat het werkt zoals gevraagd.'],
            ['  · Nee zonder toelichting  → laagste score. We nemen aan dat het ontbreekt.'],
            ['  · Ja of Nee MET toelichting → wij beoordelen deze regels handmatig.'],
            ['  · Deels                    → wij beoordelen deze regels handmatig.'],
            [''],
            ['Tip: geef alleen toelichting als de nuance er echt toe doet — dat voorkomt'],
            ['onnodige handmatige review en versnelt het proces voor ons allebei.'],
            [''],
            ['KNOCK-OUT-REGELS (MoSCoW = "Knock-out"):'],
            ['  · Nee zonder toelichting op een knock-out → directe afwijzing.'],
            ['  · Nee mét toelichting → wij beoordelen of uitzondering mogelijk is.'],
            [''],
            ['LET OP: de kolommen "Nr", "Domein", "Titel", "Omschrijving" en "MoSCoW"'],
            ['zijn informatief en worden bij upload genegeerd. De match gebeurt op "Nr".'],
        ],
        'inst_labels_bold' => 'A3:A5',
    ],
    'en' => [
        'scope_titles' => [
            'FUNC' => 'DKG Functional Requirements',
            'NFR'  => 'DKG Non Functional Requirements',
            'VEND' => 'DKG Vendor Requirements',
            'LIC'  => 'DKG Licence Requirements',
            'SUP'  => 'DKG Support Requirements',
        ],
        'headers' => [
            'nr'           => 'No.',
            'domein'       => 'Domain',
            'titel'        => 'Title',
            'omschrijving' => 'Description',
            'moscow'       => 'MoSCoW',
            'standaard'    => 'Standard Yes / No / Partial',
            'toelichting'  => 'Comments',
        ],
        'banner_right'   => 'To be completed by Vendor',
        'moscow'         => ['eis' => 'Must', 'wens' => 'Should', 'ko' => 'Knock-out'],
        'yes'            => 'Yes',
        'no'             => 'No',
        'partial'        => 'Partial',
        'dv_err_title'   => 'Invalid value',
        'dv_err'         => 'Choose Yes, No or Partial.',
        'dv_prompt_title'=> 'Standard Yes / No / Partial',
        'dv_prompt'      => 'Does the standard solution meet this requirement fully (Yes), not (No) or partially (Partial)?',
        'instructies_tab'=> 'Instructions',
        'instructies'    => [
            ['Completion instructions'],
            [''],
            ['Project',      '{traject}'],
            ['Vendor',       '{lev}'],
            ['Exported',     '{date}'],
            [''],
            ['Each main category has its own tab (FUNC, NFR, VEND, LIC, SUP).'],
            [''],
            ['For each row, fill in the "Standard Yes / No / Partial" column with one of:'],
            ['  Yes      — the standard solution fully meets this requirement'],
            ['  No       — the standard solution does not meet it (without customisation)'],
            ['  Partial  — the standard solution partially meets it — explain in Comments'],
            [''],
            ['"Comments" is optional — use it to substantiate Yes/No,'],
            ['e.g. under which conditions, which version/module is required, etc.'],
            [''],
            ['AUTOMATIC SCORING — how we process your answers:'],
            ['  · Yes without comment → highest score. We assume it works as requested.'],
            ['  · No without comment  → lowest score. We assume it is missing.'],
            ['  · Yes or No WITH comment → we review these rows manually.'],
            ['  · Partial              → we review these rows manually.'],
            [''],
            ['Tip: only add a comment if the nuance truly matters — this avoids'],
            ['unnecessary manual review and speeds up the process for both of us.'],
            [''],
            ['KNOCK-OUT RULES (MoSCoW = "Knock-out"):'],
            ['  · No without comment on a knock-out → immediate rejection.'],
            ['  · No with comment → we assess whether an exception is possible.'],
            [''],
            ['NOTE: the columns "No.", "Domain", "Title", "Description" and "MoSCoW"'],
            ['are informational and are ignored on upload. Matching is done on "No."'],
        ],
        'inst_labels_bold' => 'A3:A5',
    ],
];

/** Kolommen (intern, lower-snake) — volgorde = visuele volgorde. */
const LEV_EXCEL_COLUMNS = [
    'nr', 'domein', 'titel', 'omschrijving', 'moscow', 'standaard', 'toelichting',
];

function lev_excel_lang(string $lang): string {
    $lang = strtolower($lang);
    return in_array($lang, LEV_EXCEL_LANGS, true) ? $lang : 'nl';
}

function lev_excel_t(string $lang): array {
    return LEV_EXCEL_I18N[lev_excel_lang($lang)];
}

function lev_excel_moscow_label(string $t, string $lang = 'nl'): string {
    $map = lev_excel_t($lang)['moscow'];
    return $map[$t] ?? $t;
}

function leverancier_excel_export(int $leverancierId, string $filename, string $lang = 'nl'): void {
    $lang = lev_excel_lang($lang);
    $t    = lev_excel_t($lang);
    $lev = db_one(
        'SELECT l.*, t.name AS traject_name
           FROM leveranciers l
           JOIN trajecten t ON t.id = l.traject_id
          WHERE l.id = :id',
        [':id' => $leverancierId]
    );
    if (!$lev) { http_response_code(404); exit('Leverancier niet gevonden.'); }
    $trajectId = (int)$lev['traject_id'];

    $reqs = db_all(
        'SELECT r.id, r.code, r.title, r.description, r.type,
                s.name AS sub_name,
                c.name AS cat_name, c.code AS cat_code,
                c.sort_order AS cat_order, s.sort_order AS sub_order,
                r.sort_order AS req_order
           FROM requirements r
           JOIN subcategorieen s ON s.id = r.subcategorie_id
           JOIN categorieen    c ON c.id = s.categorie_id
          WHERE r.traject_id = :t
          ORDER BY c.sort_order, s.sort_order, r.sort_order, r.id',
        [':t' => $trajectId]
    );

    $answers = db_all(
        'SELECT requirement_id, answer_choice, answer_text
           FROM leverancier_answers
          WHERE leverancier_id = :l',
        [':l' => $leverancierId]
    );
    $aByReq = [];
    foreach ($answers as $a) $aByReq[(int)$a['requirement_id']] = $a;

    $byScope = [];
    foreach ($reqs as $r) $byScope[$r['cat_code']][] = $r;

    // Alle subcategorieën per scope, óók voor scopes zonder requirements.
    $subRows = db_all(
        'SELECT s.id, s.name, c.code AS cat_code
           FROM subcategorieen s
           JOIN categorieen c ON c.id = s.categorie_id
          WHERE s.traject_id = :t
          ORDER BY c.sort_order, s.sort_order, s.id',
        [':t' => $trajectId]
    );
    $subsByScope = [];
    foreach ($subRows as $sr) $subsByScope[$sr['cat_code']][] = $sr['name'];

    $ss = new Spreadsheet();
    $ss->removeSheetByIndex(0);

    $instTab = $t['instructies_tab'];
    $inst = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($ss, $instTab);
    $ss->addSheet($inst);
    lev_excel_build_instructions($inst, (string)$lev['traject_name'], (string)$lev['name'], $lang);

    foreach (LEV_EXCEL_SCOPES as $scope) {
        $rows = $byScope[$scope] ?? [];
        $subs = $subsByScope[$scope] ?? [];
        $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($ss, $scope);
        $ss->addSheet($sheet);
        lev_excel_build_sheet($sheet, $scope, $rows, $aByReq, $lang, $subs);
    }

    $ss->setActiveSheetIndexByName($instTab);
    lev_excel_send($ss, $filename);
}

function lev_excel_build_instructions(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s,
    string $trajectName,
    string $levName,
    string $lang = 'nl'
): void {
    $t = lev_excel_t($lang);
    $rows = [];
    $repl = ['{traject}' => $trajectName, '{lev}' => $levName, '{date}' => date('Y-m-d H:i')];
    foreach ($t['instructies'] as $r) {
        $rows[] = array_map(fn($c) => strtr((string)$c, $repl), $r);
    }
    $s->fromArray($rows, null, 'A1');
    $s->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $s->getStyle($t['inst_labels_bold'])->getFont()->setBold(true);
    $s->getColumnDimension('A')->setWidth(28);
    $s->getColumnDimension('B')->setWidth(64);
}

/**
 * Bouwt één scope-tabblad met banner + headers + datarijen + borders.
 */
function lev_excel_build_sheet(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s,
    string $scope,
    array $reqs,
    array $aByReq,
    string $lang = 'nl',
    array $subNames = []
): void {
    $t       = lev_excel_t($lang);
    $headers = $t['headers'];
    $titles  = $t['scope_titles'];
    $yesLbl  = $t['yes'];
    $noLbl   = $t['no'];
    $partLbl = $t['partial'] ?? 'Deels';
    // Kleuren (hex zonder #)
    $pink   = 'B33771'; // banner links + header linker-blok
    $pinkLt = 'F7D4E3'; // lichtere tint voor zebra
    $green  = '3AA55A';
    $greenLt= 'D9F0DE';
    $white  = 'FFFFFF';
    $borderGray = 'BBBBBB';

    $cols = LEV_EXCEL_COLUMNS;
    $letters = [];
    foreach ($cols as $i => $_) {
        $letters[] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
    }
    // Linker-blok: nr t/m moscow (kolommen 0..4)
    $leftFirst  = $letters[0];
    $leftLast   = $letters[4]; // moscow
    // Rechter-blok: standaard + toelichting
    $rightFirst = $letters[5];
    $rightLast  = end($letters);

    // ── Rij 1: banner ──────────────────────────────────────────────────────
    $s->setCellValue($leftFirst . '1', $titles[$scope] ?? $scope);
    $s->mergeCells($leftFirst . '1:' . $leftLast . '1');
    $s->getStyle($leftFirst . '1:' . $leftLast . '1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => $white]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $pink]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER],
    ]);

    $s->setCellValue($rightFirst . '1', $t['banner_right']);
    $s->mergeCells($rightFirst . '1:' . $rightLast . '1');
    $s->getStyle($rightFirst . '1:' . $rightLast . '1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => $white]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $green]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER],
    ]);
    $s->getRowDimension(1)->setRowHeight(28);

    // ── Rij 2: kolomkoppen ─────────────────────────────────────────────────
    foreach ($cols as $i => $code) {
        $cell = $letters[$i] . '2';
        $s->setCellValue($cell, $headers[$code]);
    }
    $s->getStyle($leftFirst . '2:' . $leftLast . '2')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => $white]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $pink]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER,
                        'wrapText'   => true],
    ]);
    $s->getStyle($rightFirst . '2:' . $rightLast . '2')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => $white]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $green]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER,
                        'wrapText'   => true],
    ]);
    $s->getRowDimension(2)->setRowHeight(34);

    // ── Lege scope: informatieve rij ───────────────────────────────────────
    if (!$reqs) {
        $msg = $lang === 'en'
            ? 'No requirements defined for this scope in this project.'
            : 'Geen requirements gedefinieerd voor deze scope in dit traject.';
        if ($subNames) {
            $label = $lang === 'en' ? 'Themes prepared' : 'Thema\'s aangemaakt';
            $msg .= "\n" . $label . ': ' . implode(' · ', $subNames);
        }
        $s->setCellValue($leftFirst . '3', $msg);
        $s->mergeCells($leftFirst . '3:' . $rightLast . '3');
        $s->getStyle($leftFirst . '3:' . $rightLast . '3')->applyFromArray([
            'font' => ['italic' => true, 'color' => ['rgb' => '666666']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F5F5']],
        ]);
        $s->getRowDimension(3)->setRowHeight($subNames ? 48 : 28);

        $widths = [
            $letters[0] => 10, $letters[1] => 22, $letters[2] => 32,
            $letters[3] => 70, $letters[4] => 12, $letters[5] => 20, $letters[6] => 40,
        ];
        foreach ($widths as $col => $w) $s->getColumnDimension($col)->setWidth($w);
        $s->freezePane('A3');
        return;
    }

    // ── Data ───────────────────────────────────────────────────────────────
    $rowNr = 3;
    foreach ($reqs as $req) {
        $ans    = $aByReq[(int)$req['id']] ?? null;
        $choice = $ans['answer_choice'] ?? '';
        $std    = '';
        if ($choice === 'volledig')   $std = $yesLbl;
        elseif ($choice === 'niet')   $std = $noLbl;
        elseif ($choice === 'deels')  $std = $partLbl;

        $domein = $req['cat_name'] . ' → ' . $req['sub_name'];

        $values = [
            $req['code'],
            $domein,
            $req['title'],
            $req['description'],
            lev_excel_moscow_label((string)$req['type'], $lang),
            $std,
            $ans['answer_text'] ?? '',
        ];
        foreach ($values as $i => $v) {
            $s->setCellValueExplicit(
                $letters[$i] . $rowNr,
                (string)($v ?? ''),
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );
        }
        $rowNr++;
    }
    $lastRow = $rowNr - 1;

    if ($lastRow >= 3) {
        // Border rond alle datacellen
        $range = $leftFirst . '3:' . $rightLast . $lastRow;
        $s->getStyle($range)->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                 'color' => ['rgb' => $borderGray]],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP,
                            'wrapText' => true],
        ]);
        // Lichtroze accent in linker-datablok (heel subtiel) — optioneel uit:
        // $s->getStyle($leftFirst . '3:' . $leftLast . $lastRow)->applyFromArray([
        //     'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF7FB']],
        // ]);
        // Lichtgroen accent in rechter-datablok (in te vullen)
        $s->getStyle($rightFirst . '3:' . $rightLast . $lastRow)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $greenLt]],
        ]);
        // MoSCoW en Nr gecentreerd
        $s->getStyle($letters[0] . '3:' . $letters[0] . $lastRow)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $s->getStyle($letters[4] . '3:' . $letters[4] . $lastRow)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $s->getStyle($letters[5] . '3:' . $letters[5] . $lastRow)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Data-validatie op "Standaard Ja / Nee"
        for ($r = 3; $r <= $lastRow; $r++) {
            $dv = $s->getCell($letters[5] . $r)->getDataValidation();
            $dv->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
            $dv->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
            $dv->setAllowBlank(true);
            $dv->setShowInputMessage(true);
            $dv->setShowErrorMessage(true);
            $dv->setShowDropDown(true);
            $dv->setErrorTitle($t['dv_err_title']);
            $dv->setError($t['dv_err']);
            $dv->setPromptTitle($t['dv_prompt_title']);
            $dv->setPrompt($t['dv_prompt']);
            $dv->setFormula1('"' . $yesLbl . ',' . $noLbl . ',' . $partLbl . '"');
        }
    }

    // Kolombreedtes (PhpSpreadsheet ondersteunt autoSize, maar vaste maten
    // ogen strakker omdat titel/omschrijving vaak lang zijn)
    $widths = [
        $letters[0] => 10,   // nr
        $letters[1] => 22,   // domein
        $letters[2] => 32,   // titel
        $letters[3] => 70,   // omschrijving
        $letters[4] => 12,   // moscow
        $letters[5] => 20,   // standaard
        $letters[6] => 40,   // toelichting
    ];
    foreach ($widths as $col => $w) {
        $s->getColumnDimension($col)->setWidth($w);
    }

    // Freeze headers
    $s->freezePane('A3');
}

function lev_excel_send(Spreadsheet $ss, string $filename): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $w = new XlsxWriter($ss);
    $w->save('php://output');
    exit;
}

/** Map binnenkomende tekst uit "Standaard Ja / Nee" naar answer_choice-enum. */
function lev_excel_parse_standaard(string $v): ?string {
    $v = mb_strtolower(trim($v));
    if ($v === '') return null;
    $map = [
        'ja'        => 'volledig',
        'yes'       => 'volledig',
        'y'         => 'volledig',
        'j'         => 'volledig',
        'true'      => 'volledig',
        'nee'       => 'niet',
        'no'        => 'niet',
        'n'         => 'niet',
        'false'     => 'niet',
        // Deels / partial
        'deels'     => 'deels',
        'partial'   => 'deels',
        'partieel'  => 'deels',
        'gedeeltelijk' => 'deels',
        // Legacy-waarden blijven werken
        'volledig'  => 'volledig',
        'niet'      => 'niet',
        'nvt'       => 'nvt',
        'n.v.t.'    => 'nvt',
    ];
    return $map[$v] ?? false; // false = ongeldig
}

/**
 * @return array{ok:bool, created:int, updated:int, skipped:int, errors:string[], rows:int}
 */
function leverancier_excel_import(int $leverancierId, string $path): array {
    $result = ['ok' => false, 'created' => 0, 'updated' => 0, 'skipped' => 0,
               'errors' => [], 'rows' => 0];

    $lev = db_one('SELECT id, traject_id FROM leveranciers WHERE id = :id',
        [':id' => $leverancierId]);
    if (!$lev) { $result['errors'][] = 'Leverancier niet gevonden.'; return $result; }
    $trajectId = (int)$lev['traject_id'];

    try {
        // Hardening: read-only en alleen de nodige sheet-tabs, om formule-evaluatie,
        // externe referenties en zip-bomb-achtige memory-explosies te vermijden.
        $reader = IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) $reader->setReadDataOnly(true);
        if (method_exists($reader, 'setReadEmptyCells')) $reader->setReadEmptyCells(false);
        $ss = $reader->load($path);
    } catch (Throwable $e) {
        $result['errors'][] = 'Kon bestand niet openen: ' . $e->getMessage();
        return $result;
    }

    $codeRows = db_all('SELECT id, code FROM requirements WHERE traject_id = :t',
        [':t' => $trajectId]);
    $reqByCode = [];
    foreach ($codeRows as $r) $reqByCode[mb_strtoupper((string)$r['code'])] = (int)$r['id'];

    $ansRows = db_all('SELECT id, requirement_id FROM leverancier_answers WHERE leverancier_id = :l',
        [':l' => $leverancierId]);
    $ansByReq = [];
    foreach ($ansRows as $a) $ansByReq[(int)$a['requirement_id']] = (int)$a['id'];

    // Welke header-varianten accepteren we voor welk veld?
    $headerAliases = [
        'code'        => ['nr', 'no.', 'no', 'code', 'nfr nr', 'fr nr', 'vend nr', 'lic nr', 'sup nr', 'number'],
        'standaard'   => ['standaard ja / nee', 'standaard ja/nee', 'standaard', 'antwoord',
                          'standard yes / no', 'standard yes/no', 'standard', 'answer'],
        'toelichting' => ['toelichting', 'opmerking', 'notes', 'comments', 'comment', 'remarks'],
    ];

    $plan = [];
    foreach ($ss->getSheetNames() as $sheetName) {
        $upper = mb_strtoupper($sheetName);
        if (!in_array($upper, LEV_EXCEL_SCOPES, true)) continue;
        $sheet = $ss->getSheetByName($sheetName);

        // Header-rij: zoek in de eerste drie rijen naar een rij die de code-kolom bevat
        $headerRow = null;
        $colByField = [];
        for ($rn = 1; $rn <= 3; $rn++) {
            $row = $sheet->rangeToArray('A' . $rn . ':' . $sheet->getHighestColumn() . $rn,
                null, true, true, true);
            $row = $row[$rn] ?? [];
            $lower = array_map(fn($v) => mb_strtolower(trim((string)$v)), $row);
            foreach ($lower as $letter => $name) {
                foreach ($headerAliases as $field => $aliases) {
                    if (in_array($name, $aliases, true) && !isset($colByField[$field])) {
                        $colByField[$field] = $letter;
                    }
                }
            }
            if (isset($colByField['code'], $colByField['standaard'])) {
                $headerRow = $rn;
                break;
            }
            $colByField = [];
        }
        if ($headerRow === null) {
            $result['errors'][] = "Tab '$sheetName': kolommen 'Nr' en 'Standaard Ja / Nee' niet gevonden in rijen 1-3.";
            continue;
        }

        $maxRow = $sheet->getHighestDataRow();
        $dataRows = $sheet->rangeToArray('A' . ($headerRow + 1) . ':' . $sheet->getHighestColumn() . $maxRow,
            null, true, true, true);
        foreach ($dataRows as $rn => $raw) {
            $get = function (string $field) use ($raw, $colByField) {
                $letter = $colByField[$field] ?? null;
                if (!$letter) return '';
                return trim((string)($raw[$letter] ?? ''));
            };

            $code = $get('code');
            if ($code === '') continue;

            $result['rows']++;
            $stdRaw = $get('standaard');
            $text   = $get('toelichting');

            if ($stdRaw === '') {
                $result['errors'][] = "Tab '$sheetName' rij $rn (nr '$code'): "
                    . "kolom 'Standaard Ja / Nee' is leeg — vul Ja of Nee in.";
                continue;
            }

            $reqId = $reqByCode[mb_strtoupper($code)] ?? null;
            if (!$reqId) {
                $result['errors'][] = "Tab '$sheetName' rij $rn: nr '$code' niet bekend in dit traject.";
                continue;
            }

            $choice = null;
            if ($stdRaw !== '') {
                $parsed = lev_excel_parse_standaard($stdRaw);
                if ($parsed === false) {
                    $result['errors'][] = "Tab '$sheetName' rij $rn: '$stdRaw' is geen geldige waarde (gebruik Ja of Nee).";
                    continue;
                }
                $choice = $parsed;
            }

            $plan[] = [
                'req'    => $reqId,
                'choice' => $choice,
                'text'   => $text !== '' ? $text : null,
            ];
        }
    }

    if ($result['errors']) return $result;
    if (!$plan) {
        if ($result['rows'] === 0) {
            $result['errors'][] = 'Geen rijen met een Nr gevonden in de tabs FUNC/NFR/VEND/LIC/SUP. '
                . 'Controleer of je de juiste tabs hebt ingevuld en dat kolom "Nr" niet leeg is.';
        } else {
            $result['errors'][] = sprintf(
                '%d rij(en) gelezen, maar geen enkele had een ingevuld antwoord of toelichting. '
                . 'Vul per regel de kolom "Standaard Ja / Nee" (of "Toelichting") in.',
                (int)$result['rows']
            );
        }
        return $result;
    }

    db_transaction(function () use ($plan, $leverancierId, $trajectId, $ansByReq, &$result) {
        $now = date('Y-m-d H:i:s');
        foreach ($plan as $p) {
            if (isset($ansByReq[$p['req']])) {
                db_update('leverancier_answers', [
                    'answer_choice' => $p['choice'],
                    'answer_text'   => $p['text'],
                    'evidence_url'  => null,
                    'updated_at'    => $now,
                ], 'id = :id', [':id' => $ansByReq[$p['req']]]);
                $result['updated']++;
            } else {
                db_insert('leverancier_answers', [
                    'traject_id'     => $trajectId,
                    'leverancier_id' => $leverancierId,
                    'requirement_id' => $p['req'],
                    'answer_choice'  => $p['choice'],
                    'answer_text'    => $p['text'],
                    'evidence_url'   => null,
                    'imported_at'    => $now,
                    'updated_at'     => $now,
                ]);
                $result['created']++;
            }
        }
    });
    $result['ok'] = true;
    return $result;
}

/**
 * Antwoorden voor een leverancier, geïndexeerd op requirement_id.
 */
function leverancier_answers_for(int $leverancierId): array {
    $rows = db_all(
        'SELECT requirement_id, answer_choice, answer_text, evidence_url, updated_at
           FROM leverancier_answers
          WHERE leverancier_id = :l',
        [':l' => $leverancierId]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['requirement_id']] = [
            'choice' => (string)($r['answer_choice'] ?? ''),
            'text'   => (string)($r['answer_text']   ?? ''),
            'url'    => (string)($r['evidence_url']  ?? ''),
            'at'     => (string)($r['updated_at']    ?? ''),
        ];
    }
    return $out;
}

/** User-facing label voor answer_choice — consistent met Excel-terminologie. */
function lev_answer_label(?string $choice): string {
    return match ((string)$choice) {
        'volledig' => 'Ja',
        'niet'     => 'Nee',
        'deels'    => 'Deels',
        'nvt'      => 'N.v.t.',
        default    => '—',
    };
}

/** Badge voor leverancier-antwoord. "Ja" (volledig) / "Nee" (niet) + Deels/Nvt. */
function leverancier_answer_badge(string $choice): string {
    $map = [
        'volledig' => ['green', 'Ja'],
        'niet'     => ['red',   'Nee'],
        'deels'    => ['amber', 'Deels'],
        'nvt'      => ['gray',  'N.v.t.'],
    ];
    if (!isset($map[$choice])) return '<span class="badge gray">—</span>';
    [$c, $l] = $map[$choice];
    return '<span class="badge ' . $c . '">' . h($l) . '</span>';
}
