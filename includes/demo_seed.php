<?php
/**
 * Demo-data: lege Excel-template + importer voor trajecten/leveranciers/requirements.
 *
 * Format (zie demo_template_xlsx voor lege variant):
 *   Sheet "Trajecten"     — name, description, status, start_date, end_date,
 *                           demo_weight_pct, app_soorten, nfr_subs, vend_subs,
 *                           impl_subs, sup_subs, lic_subs
 *   Sheet "Leveranciers"  — traject_name, name, contact_name, contact_email,
 *                           website, status, notes
 *   Sheet "Requirements"  — traject_name, scope, app_soort, subcategorie,
 *                           code, titel, omschrijving, type
 *
 * Verwijst naar entiteiten op naam (geen IDs in de Excel).
 * Vereist een geseede structuur (App soorten + subcategorie-templates).
 *
 * De importer praat direct met db_insert (geen audit_log) zodat hij ook
 * tijdens de install-wizard draait, vóór er een ingelogde user bestaat.
 */

if (!defined('APP_BOOT')) { http_response_code(403); exit('Forbidden'); }

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

const DEMO_SHEETS = [
    'Trajecten'    => [
        'name', 'description', 'status', 'start_date', 'end_date',
        'demo_weight_pct',
        'app_soorten',
        'nfr_subs', 'vend_subs', 'impl_subs', 'sup_subs', 'lic_subs',
    ],
    'Leveranciers' => [
        'traject_name', 'name', 'contact_name', 'contact_email',
        'website', 'status', 'notes',
    ],
    'Requirements' => [
        'traject_name', 'scope', 'app_soort', 'subcategorie',
        'code', 'titel', 'omschrijving', 'type',
    ],
];

const DEMO_TRAJ_STATUSES = ['concept', 'actief', 'afgerond', 'gearchiveerd'];
const DEMO_LEV_STATUSES  = ['actief', 'onder_review', 'afgewezen'];
const DEMO_REQ_TYPES     = ['eis', 'wens', 'ko'];
const DEMO_SCOPES        = ['FUNC', 'NFR', 'VEND', 'IMPL', 'SUP', 'LIC'];

/** Download een lege demo-template als .xlsx. */
function demo_template_xlsx(string $filename): void {
    $ss = new Spreadsheet();
    $ss->removeSheetByIndex(0);

    // Instructies-tab
    $info = $ss->createSheet();
    $info->setTitle('Instructies');
    $info->fromArray([
        ['Demo-template'],
        [''],
        ['Vul onderstaande tabbladen. Tabbladnamen, kolomvolgorde en kolomnamen niet wijzigen.'],
        ['Demo-data wordt bovenop een al geseede structuur (App soorten + subcat-templates) ingelezen.'],
        [''],
        ['── Trajecten ─────────────────────────────────────────────────────────'],
        ['name             — uniek binnen dit bestand. Verplicht.'],
        ['description      — optioneel.'],
        ['status           — concept | actief | afgerond | gearchiveerd (default concept).'],
        ['start_date       — YYYY-MM-DD of leeg.'],
        ['end_date         — YYYY-MM-DD of leeg.'],
        ['demo_weight_pct  — 0–100 (default 20).'],
        ['app_soorten      — semicolon-separated namen uit App soorten (FUNC).'],
        ['nfr_subs etc.    — semicolon-separated subcat-template namen per scope.'],
        [''],
        ['── Leveranciers ──────────────────────────────────────────────────────'],
        ['traject_name     — moet voorkomen in tabblad Trajecten.'],
        ['status           — actief | onder_review | afgewezen (default actief).'],
        [''],
        ['── Requirements ──────────────────────────────────────────────────────'],
        ['traject_name     — moet voorkomen in tabblad Trajecten.'],
        ['scope            — FUNC | NFR | VEND | IMPL | SUP | LIC.'],
        ['app_soort        — verplicht voor FUNC; moet bij dat traject horen.'],
        ['subcategorie     — naam exact zoals in tabblad Trajecten gekozen.'],
        ['code             — leeg laten = auto-generatie (FR-001 / NFR-001 / …).'],
        ['type             — eis | wens | ko.'],
    ], null, 'A1');
    $info->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $info->getColumnDimension('A')->setWidth(110);

    foreach (DEMO_SHEETS as $title => $cols) {
        $sh = $ss->createSheet();
        $sh->setTitle($title);
        $sh->fromArray([$cols], null, 'A1');
        $last = _demo_col_letter(count($cols));
        $sh->getStyle('A1:' . $last . '1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EC4899']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        foreach ($cols as $i => $_) $sh->getColumnDimensionByColumn($i + 1)->setWidth(24);
        $sh->freezePane('A2');
    }

    $ss->setActiveSheetIndex(0);

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    (new XlsxWriter($ss))->save('php://output');
    exit;
}

function _demo_col_letter(int $n): string {
    $s = '';
    while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); }
    return $s;
}

/**
 * Importeer demo-data uit .xlsx. Strict, transactioneel, all-or-nothing.
 * Vereist: structuur is al geseed (App soorten + subcat-templates).
 *
 * @return array{trajecten:int, leveranciers:int, requirements:int}
 */
function demo_import_xlsx(string $path): array {
    if ((int)db_value('SELECT COUNT(*) FROM applicatiesoorten') === 0) {
        throw new RuntimeException('Demo-import vereist een geseede structuur — laad eerst seed-data.');
    }
    if ((int)db_value('SELECT COUNT(*) FROM trajecten') > 0) {
        throw new RuntimeException('Demo-import alleen toegestaan op een database zonder trajecten.');
    }

    try {
        $ss = IOFactory::load($path);
    } catch (Throwable $e) {
        throw new RuntimeException('Kon Excel niet lezen: ' . $e->getMessage());
    }

    foreach (array_keys(DEMO_SHEETS) as $sheetName) {
        if ($ss->getSheetByName($sheetName) === null) {
            throw new RuntimeException("Tabblad '$sheetName' ontbreekt.");
        }
    }

    $trajRows = _demo_read_sheet($ss->getSheetByName('Trajecten'),    DEMO_SHEETS['Trajecten']);
    $levRows  = _demo_read_sheet($ss->getSheetByName('Leveranciers'), DEMO_SHEETS['Leveranciers']);
    $reqRows  = _demo_read_sheet($ss->getSheetByName('Requirements'), DEMO_SHEETS['Requirements']);

    // App-soort lookup (lowercase name → id)
    $appByName = [];
    foreach (db_all('SELECT id, name FROM applicatiesoorten') as $a) {
        $appByName[mb_strtolower(trim($a['name']))] = (int)$a['id'];
    }

    // Subcat-template lookup per categorie (voor NFR/VEND/IMPL/SUP/LIC: name → id)
    $tplByCatName = [];   // ['NFR' => ['name' => id, …], …]
    $tplByCatAppName = [];// FUNC: app_id → name → tpl_id
    $catIdByCode  = [];
    foreach (db_all('SELECT id, code FROM categorieen') as $c) $catIdByCode[$c['code']] = (int)$c['id'];

    foreach (db_all(
        'SELECT t.id, t.applicatiesoort_id, t.name, t.bron, t.description, t.sort_order,
                c.code AS cat_code
           FROM subcategorie_templates t
           JOIN categorieen c ON c.id = t.categorie_id'
    ) as $t) {
        if ($t['cat_code'] === 'FUNC') {
            $aId = (int)$t['applicatiesoort_id'];
            $tplByCatAppName[$aId][mb_strtolower($t['name'])] = $t;
        } else {
            $tplByCatName[$t['cat_code']][mb_strtolower($t['name'])] = $t;
        }
    }

    // ── Validatie Trajecten ─────────────────────────────────────────
    $trajByName = [];
    foreach ($trajRows as $i => $r) {
        $rn   = $i + 2;
        $name = trim((string)$r['name']);
        if ($name === '') throw new RuntimeException("Trajecten rij $rn: name leeg.");
        $key = mb_strtolower($name);
        if (isset($trajByName[$key])) throw new RuntimeException("Trajecten rij $rn: dubbele name '$name'.");

        $status = trim((string)($r['status'] ?? '')) ?: 'concept';
        if (!in_array($status, DEMO_TRAJ_STATUSES, true)) {
            throw new RuntimeException("Trajecten rij $rn: status '$status' ongeldig.");
        }

        // App soorten (FUNC)
        $appNames = _demo_split_list((string)($r['app_soorten'] ?? ''));
        $appIds = [];
        foreach ($appNames as $an) {
            $aid = $appByName[mb_strtolower($an)] ?? null;
            if (!$aid) throw new RuntimeException("Trajecten rij $rn: onbekende app_soort '$an'.");
            $appIds[] = $aid;
        }

        // Per scope: subcat-namen → template-rij
        $tplsByScope = [];
        foreach (['NFR', 'VEND', 'IMPL', 'SUP', 'LIC'] as $scope) {
            $col = strtolower($scope) . '_subs';
            $names = _demo_split_list((string)($r[$col] ?? ''));
            foreach ($names as $tn) {
                $tpl = $tplByCatName[$scope][mb_strtolower($tn)] ?? null;
                if (!$tpl) throw new RuntimeException("Trajecten rij $rn: onbekende $scope-subcategorie '$tn'.");
                $tplsByScope[$scope][] = $tpl;
            }
        }

        $trajByName[$key] = [
            '__row'    => $rn,
            'name'     => $name,
            'description'     => trim((string)($r['description'] ?? '')) ?: null,
            'status'   => $status,
            'start_date' => _demo_date_or_null((string)($r['start_date'] ?? ''), $rn, 'Trajecten', 'start_date'),
            'end_date'   => _demo_date_or_null((string)($r['end_date']   ?? ''), $rn, 'Trajecten', 'end_date'),
            'demo_weight_pct' => _demo_demo_weight((string)($r['demo_weight_pct'] ?? ''), $rn),
            'app_ids'  => $appIds,
            'tpls'     => $tplsByScope,
        ];
    }

    // ── Validatie Leveranciers ─────────────────────────────────────
    $levToInsert = [];
    foreach ($levRows as $i => $r) {
        $rn = $i + 2;
        $tn = mb_strtolower(trim((string)$r['traject_name']));
        if ($tn === '' || !isset($trajByName[$tn])) {
            throw new RuntimeException("Leveranciers rij $rn: onbekende traject_name '{$r['traject_name']}'.");
        }
        $name = trim((string)$r['name']);
        if ($name === '') throw new RuntimeException("Leveranciers rij $rn: name leeg.");

        $status = trim((string)($r['status'] ?? '')) ?: 'actief';
        if (!in_array($status, DEMO_LEV_STATUSES, true)) {
            throw new RuntimeException("Leveranciers rij $rn: status '$status' ongeldig.");
        }
        $levToInsert[] = [
            '__row'        => $rn,
            'traject_key'  => $tn,
            'name'         => $name,
            'contact_name' => trim((string)($r['contact_name'] ?? '')) ?: null,
            'contact_email'=> trim((string)($r['contact_email']?? '')) ?: null,
            'website'      => trim((string)($r['website']      ?? '')) ?: null,
            'status'       => $status,
            'notes'        => trim((string)($r['notes']        ?? '')) ?: null,
        ];
    }

    // ── Validatie Requirements ─────────────────────────────────────
    $reqToInsert = [];
    foreach ($reqRows as $i => $r) {
        $rn = $i + 2;
        $tnKey = mb_strtolower(trim((string)$r['traject_name']));
        if ($tnKey === '' || !isset($trajByName[$tnKey])) {
            throw new RuntimeException("Requirements rij $rn: onbekende traject_name '{$r['traject_name']}'.");
        }
        $traj  = $trajByName[$tnKey];

        $scope = strtoupper(trim((string)$r['scope']));
        if (!in_array($scope, DEMO_SCOPES, true)) {
            throw new RuntimeException("Requirements rij $rn: scope '$scope' ongeldig.");
        }
        $title = trim((string)$r['titel']);
        if ($title === '') throw new RuntimeException("Requirements rij $rn: titel leeg.");
        $type  = mb_strtolower(trim((string)$r['type']));
        if (!in_array($type, DEMO_REQ_TYPES, true)) {
            throw new RuntimeException("Requirements rij $rn: type '$type' ongeldig.");
        }

        $subName = trim((string)$r['subcategorie']);
        if ($subName === '') throw new RuntimeException("Requirements rij $rn: subcategorie leeg.");

        if ($scope === 'FUNC') {
            $appName = trim((string)($r['app_soort'] ?? ''));
            if ($appName === '') throw new RuntimeException("Requirements rij $rn: app_soort leeg voor FUNC.");
            $aId = $appByName[mb_strtolower($appName)] ?? null;
            if (!$aId || !in_array($aId, $traj['app_ids'], true)) {
                throw new RuntimeException("Requirements rij $rn: app_soort '$appName' niet gekoppeld aan traject '{$traj['name']}'.");
            }
            $tpl = $tplByCatAppName[$aId][mb_strtolower($subName)] ?? null;
            if (!$tpl) {
                throw new RuntimeException("Requirements rij $rn: subcategorie '$subName' onbekend onder app_soort '$appName'.");
            }
        } else {
            $tpl = $tplByCatName[$scope][mb_strtolower($subName)] ?? null;
            if (!$tpl) {
                throw new RuntimeException("Requirements rij $rn: subcategorie '$subName' onbekend binnen $scope.");
            }
            // Subcat moet ook door dit traject gekozen zijn
            $chosen = false;
            foreach ($traj['tpls'][$scope] ?? [] as $chosenTpl) {
                if ((int)$chosenTpl['id'] === (int)$tpl['id']) { $chosen = true; break; }
            }
            if (!$chosen) {
                throw new RuntimeException("Requirements rij $rn: subcategorie '$subName' niet gekozen door traject '{$traj['name']}' (voeg toe aan {$scope}_subs).");
            }
        }

        $reqToInsert[] = [
            '__row'       => $rn,
            'traject_key' => $tnKey,
            'scope'       => $scope,
            'tpl_id'      => (int)$tpl['id'],
            'code'        => trim((string)($r['code'] ?? '')) ?: null,
            'title'       => $title,
            'description' => trim((string)($r['omschrijving'] ?? '')) ?: null,
            'type'        => $type,
        ];
    }

    // ── Schrijven ───────────────────────────────────────────────────
    $now = date('Y-m-d H:i:s');
    $createdTraj = 0; $createdLev = 0; $createdReq = 0;

    db_transaction(function () use (
        &$trajByName, $levToInsert, $reqToInsert,
        $catIdByCode, &$createdTraj, &$createdLev, &$createdReq, $now
    ) {
        // 1) Trajecten + subcategorieen + weights
        foreach ($trajByName as $key => &$t) {
            $tid = db_insert('trajecten', [
                'name'           => $t['name'],
                'description'    => $t['description'],
                'status'         => $t['status'],
                'start_date'     => $t['start_date'],
                'end_date'       => $t['end_date'],
                'demo_weight_pct'=> $t['demo_weight_pct'],
                'created_by'     => null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            $t['id'] = $tid;
            $createdTraj++;

            // FUNC-subcats: alle templates van geselecteerde app soorten
            $subIdByTplId = [];
            if ($t['app_ids']) {
                $in = implode(',', array_fill(0, count($t['app_ids']), '?'));
                $tpls = db_all(
                    "SELECT id, applicatiesoort_id, name, bron, description, sort_order
                       FROM subcategorie_templates
                      WHERE categorie_id = ? AND applicatiesoort_id IN ($in)
                      ORDER BY applicatiesoort_id, name, id",
                    array_merge([$catIdByCode['FUNC']], $t['app_ids'])
                );
                foreach ($tpls as $tpl) {
                    $sid = db_insert('subcategorieen', [
                        'categorie_id'       => $catIdByCode['FUNC'],
                        'traject_id'         => $tid,
                        'applicatiesoort_id' => (int)$tpl['applicatiesoort_id'],
                        'name'               => $tpl['name'],
                        'bron'               => $tpl['bron'] ?? null,
                        'description'        => $tpl['description'] ?? null,
                        'sort_order'         => (int)$tpl['sort_order'],
                    ]);
                    $subIdByTplId[(int)$tpl['id']] = $sid;
                }
            }

            // Overige scopes: per gekozen template
            foreach (['NFR', 'VEND', 'IMPL', 'SUP', 'LIC'] as $scope) {
                foreach ($t['tpls'][$scope] ?? [] as $tpl) {
                    $sid = db_insert('subcategorieen', [
                        'categorie_id'       => $catIdByCode[$scope],
                        'traject_id'         => $tid,
                        'applicatiesoort_id' => null,
                        'name'               => $tpl['name'],
                        'bron'               => $tpl['bron'] ?? null,
                        'description'        => $tpl['description'] ?? null,
                        'sort_order'         => (int)$tpl['sort_order'],
                    ]);
                    $subIdByTplId[(int)$tpl['id']] = $sid;
                }
            }
            $t['sub_id_by_tpl_id'] = $subIdByTplId;

            // Weights: gelijkmatig per categorie, gelijkmatig per subcat
            $catRows = db_all('SELECT id FROM categorieen ORDER BY sort_order, id');
            $catWeight = round(100 / max(1, count($catRows)), 3);
            foreach ($catRows as $c) {
                db_insert('weights', [
                    'traject_id'      => $tid,
                    'categorie_id'    => (int)$c['id'],
                    'subcategorie_id' => null,
                    'weight'          => $catWeight,
                ]);
                $subs = db_all(
                    'SELECT id FROM subcategorieen WHERE categorie_id = :c AND traject_id = :t ORDER BY sort_order, id',
                    [':c' => (int)$c['id'], ':t' => $tid]
                );
                if (!$subs) continue;
                $sw = round(100 / count($subs), 3);
                foreach ($subs as $s) {
                    db_insert('weights', [
                        'traject_id'      => $tid,
                        'categorie_id'    => null,
                        'subcategorie_id' => (int)$s['id'],
                        'weight'          => $sw,
                    ]);
                }
            }
        }
        unset($t);

        // 2) Leveranciers
        foreach ($levToInsert as $L) {
            db_insert('leveranciers', [
                'traject_id'    => $trajByName[$L['traject_key']]['id'],
                'name'          => $L['name'],
                'contact_name'  => $L['contact_name'],
                'contact_email' => $L['contact_email'],
                'website'       => $L['website'],
                'notes'         => $L['notes'],
                'status'        => $L['status'],
                'created_at'    => $now,
            ]);
            $createdLev++;
        }

        // 3) Requirements (met auto-code per traject + scope)
        $codePrefix = ['FUNC' => 'FR', 'NFR' => 'NFR', 'VEND' => 'VR', 'IMPL' => 'IR', 'SUP' => 'SR', 'LIC' => 'LR'];
        $codeCounters = []; // [traject_id][prefix] => int
        foreach ($reqToInsert as $R) {
            $traj = $trajByName[$R['traject_key']];
            $tid  = $traj['id'];
            $sid  = $traj['sub_id_by_tpl_id'][$R['tpl_id']] ?? null;
            if (!$sid) {
                throw new RuntimeException("Requirements rij {$R['__row']}: kon subcategorie niet matchen na insert.");
            }
            $pfx = $codePrefix[$R['scope']];
            if ($R['code'] !== null) {
                $code = $R['code'];
            } else {
                $codeCounters[$tid][$pfx] = ($codeCounters[$tid][$pfx] ?? 0) + 1;
                $code = sprintf('%s-%03d', $pfx, $codeCounters[$tid][$pfx]);
            }
            $maxOrder = (int)db_value(
                'SELECT COALESCE(MAX(sort_order),0) FROM requirements WHERE traject_id = :t AND subcategorie_id = :s',
                [':t' => $tid, ':s' => $sid]
            );
            db_insert('requirements', [
                'traject_id'      => $tid,
                'subcategorie_id' => $sid,
                'code'            => $code,
                'title'           => $R['title'],
                'description'     => $R['description'],
                'type'            => $R['type'],
                'sort_order'      => $maxOrder + 10,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
            $createdReq++;
        }
    });

    // ── Fase 4: scoring-data (optioneel — alleen als extra sheets aanwezig zijn) ──
    $scoringResult = _demo_import_scoring($ss, $now);

    return array_merge([
        'trajecten'    => $createdTraj,
        'leveranciers' => $createdLev,
        'requirements' => $createdReq,
    ], $scoringResult);
}

/**
 * Importeert scoring-data uit de optionele sheets van demo_compleet.xlsx:
 *   Antwoorden_Alpha, Beoordelaars, Scores, Demo_Scores, Demo_Open
 *
 * Werkt altijd op "Leverancier Alpha" in het eerste gevonden traject.
 * Wordt overgeslagen als de sheets ontbreken (achterwaarts compatibel met demo_beperkt).
 *
 * @return array{antwoorden?:int, rondes?:int, scores?:int, demo_scores?:int, skipped?:bool}
 */
function _demo_import_scoring(Spreadsheet $ss, string $now): array {
    // Alleen uitvoeren als alle scoring-sheets aanwezig zijn
    $needed = ['Antwoorden_Alpha', 'Beoordelaars', 'Scores', 'Demo_Scores', 'Demo_Open'];
    foreach ($needed as $sn) {
        if ($ss->getSheetByName($sn) === null) {
            return ['skipped' => true];
        }
    }

    // ── Zoek Leverancier Alpha + traject ────────────────────────────────────
    $levRow = db_one(
        "SELECT l.id, l.traject_id
           FROM leveranciers l
          WHERE l.name = 'Leverancier Alpha'
          ORDER BY l.id ASC LIMIT 1"
    );
    if (!$levRow) {
        return ['skipped' => true, 'reason' => 'Leverancier Alpha niet gevonden in DB'];
    }
    $levId  = (int)$levRow['id'];
    $trajId = (int)$levRow['traject_id'];

    // ── Requirements opzoeken per code ──────────────────────────────────────
    $reqByCode = [];
    foreach (db_all('SELECT id, code FROM requirements WHERE traject_id = :t', [':t' => $trajId]) as $r) {
        $reqByCode[trim((string)$r['code'])] = (int)$r['id'];
    }

    // ── Lees de sheets als ruwe rijen ────────────────────────────────────────
    $antwoordRows  = _demo_read_rows($ss->getSheetByName('Antwoorden_Alpha'), 1);  // 1 header-rij
    $beoordelaarsRows = _demo_read_rows($ss->getSheetByName('Beoordelaars'), 1);
    $scoresRows    = _demo_read_rows($ss->getSheetByName('Scores'), 1);
    $demoScoresRows = _demo_read_rows($ss->getSheetByName('Demo_Scores'), 1);
    $demoOpenRows  = _demo_read_rows($ss->getSheetByName('Demo_Open'), 1);

    // Antwoord-mapping (Excel → DB enum)
    $choiceMap = ['Ja' => 'volledig', 'Deels' => 'deels', 'Nee' => 'niet'];

    $nAntw = 0; $nScores = 0; $nDemoScores = 0; $nDemoOpen = 0; $nDeelnemers = 0;

    db_transaction(function () use (
        $levId, $trajId, $now,
        $reqByCode, $choiceMap,
        $antwoordRows, $beoordelaarsRows, $scoresRows, $demoScoresRows, $demoOpenRows,
        &$nAntw, &$nScores, &$nDemoScores, &$nDemoOpen, &$nDeelnemers
    ) {
        // ── 1) Leverancier-antwoorden ────────────────────────────────────────
        // Cols: 0=Nr, 1=Scope, 2=Domein, 3=Titel, 4=MoSCoW, 5=Antwoord, 6=Toelichting
        foreach ($antwoordRows as $row) {
            $code   = trim((string)($row[0] ?? ''));
            $antw   = trim((string)($row[5] ?? ''));
            $toel   = trim((string)($row[6] ?? ''));
            $reqId  = $reqByCode[$code] ?? null;
            if (!$reqId || !isset($choiceMap[$antw])) continue;

            $choice  = $choiceMap[$antw];
            $ansText = ($toel !== '' && strtolower($toel) !== 'nan') ? $toel : null;

            // Upsert — rij kan al bestaan als seeder herhaald wordt
            $exists = db_value(
                'SELECT id FROM leverancier_answers WHERE leverancier_id = :l AND requirement_id = :r',
                [':l' => $levId, ':r' => $reqId]
            );
            if ($exists) continue;

            db_insert('leverancier_answers', [
                'traject_id'    => $trajId,
                'leverancier_id'=> $levId,
                'requirement_id'=> $reqId,
                'answer_choice' => $choice,
                'answer_text'   => $ansText,
                'evidence_url'  => null,
                'imported_at'   => $now,
                'updated_at'    => $now,
            ]);
            $nAntw++;
        }

        // Dummy upload-record zodat de Antwoorden-pagina het bestand kan tonen
        $hasUpload = db_value('SELECT id FROM leverancier_uploads WHERE leverancier_id = :l', [':l' => $levId]);
        if (!$hasUpload) {
            db_insert('leverancier_uploads', [
                'traject_id'     => $trajId,
                'leverancier_id' => $levId,
                'original_name'  => 'requirements_ERP-selectie_demo_Leverancier_Alpha_ingevuld.xlsx',
                'stored_path'    => 'demo_seed',
                'uploaded_by'    => null,
                'uploaded_at'    => $now,
                'rows_total'     => $nAntw,
                'rows_auto'      => 0,
                'rows_manual'    => 0,
                'rows_ko_fail'   => 0,
            ]);
        }

        // ── 2) Demo-vragenlijst kopiëren naar dit traject ────────────────────
        $haveDemoQ = (int)db_value(
            'SELECT COUNT(*) FROM traject_demo_questions WHERE traject_id = :t',
            [':t' => $trajId]
        );
        if ($haveDemoQ === 0) {
            $masterQ = db_all(
                'SELECT id, block, sort_order, text, active FROM demo_question_catalog ORDER BY block, sort_order, id'
            );
            foreach ($masterQ as $mq) {
                db_insert('traject_demo_questions', [
                    'traject_id'        => $trajId,
                    'block'             => (int)$mq['block'],
                    'sort_order'        => (int)$mq['sort_order'],
                    'text'              => (string)$mq['text'],
                    'active'            => (int)$mq['active'],
                    'source_catalog_id' => (int)$mq['id'],
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
            }
        }

        // Demo-vragen per tekst opzoeken (voor matching met Demo_Scores sheet)
        $tdqByText = [];
        foreach (db_all(
            'SELECT id, block, text FROM traject_demo_questions WHERE traject_id = :t AND active = 1',
            [':t' => $trajId]
        ) as $q) {
            $tdqByText[trim((string)$q['text'])] = ['id' => (int)$q['id'], 'block' => (int)$q['block']];
        }

        // ── 3) Beoordelaars inlezen ──────────────────────────────────────────
        // Cols: 0=Naam, 1=E-mail, 2=Rol, 3=Organisatie, 4=Opmerking, 5=Scopes
        $beoordelaars = [];
        foreach ($beoordelaarsRows as $row) {
            $naam  = trim((string)($row[0] ?? ''));
            $email = trim((string)($row[1] ?? ''));
            if ($naam === '' || $email === '') continue;
            $scopesRaw = trim((string)($row[5] ?? ''));
            $beoScopes = $scopesRaw !== ''
                ? array_values(array_filter(array_map('trim', explode(';', $scopesRaw))))
                : [];
            $beoordelaars[] = ['name' => $naam, 'email' => $email, 'scopes' => $beoScopes];
        }
        if (empty($beoordelaars)) return;

        // ── 3b) Collega's (traject_deelnemers) aanmaken ──────────────────────
        $tdIds = []; // beoordelaar-index → traject_deelnemer_id
        foreach ($beoordelaars as $idx => $beo) {
            $tdId = (int)(db_value(
                'SELECT id FROM traject_deelnemers WHERE traject_id = :t AND email = :e',
                [':t' => $trajId, ':e' => $beo['email']]
            ) ?: 0);
            if (!$tdId) {
                $tdId = db_insert('traject_deelnemers', [
                    'traject_id' => $trajId,
                    'user_id'    => null,
                    'name'       => $beo['name'],
                    'email'      => $beo['email'],
                    'created_at' => $now,
                ]);
                $nDeelnemers++;
            }
            $tdIds[$idx] = $tdId;

            // Scope-toewijzingen
            foreach ($beo['scopes'] as $s) {
                $hasScope = db_value(
                    'SELECT 1 FROM traject_deelnemer_scopes WHERE traject_deelnemer_id = :d AND scope = :s',
                    [':d' => $tdId, ':s' => $s]
                );
                if (!$hasScope) {
                    db_insert('traject_deelnemer_scopes', [
                        'traject_deelnemer_id' => $tdId,
                        'scope'                => $s,
                    ]);
                }
            }
        }

        // ── 4) Scoring-rondes per scope aanmaken ─────────────────────────────
        // Scores sheet cols: 0=Nr, 1=Scope, ..., 6-10=scores per beoordelaar (zelfde volgorde)
        $scopes = ['FUNC', 'NFR', 'VEND', 'IMPL', 'SUP', 'LIC'];
        $rondeIds = []; // scope → ronde_id
        $deelnemerIds = []; // scope → [index → deelnemer_id]

        $tokenExpiry = date('Y-m-d H:i:s', strtotime('+10 years', strtotime($now)));

        foreach ($scopes as $scope) {
            // Ronde aanmaken (of bestaande hergebruiken)
            $rondeId = (int)(db_value(
                'SELECT id FROM scoring_rondes WHERE traject_id = :t AND leverancier_id = :l AND scope = :s',
                [':t' => $trajId, ':l' => $levId, ':s' => $scope]
            ) ?: 0);

            if (!$rondeId) {
                $rondeId = db_insert('scoring_rondes', [
                    'traject_id'     => $trajId,
                    'leverancier_id' => $levId,
                    'scope'          => $scope,
                    'name'           => $scope . '-ronde',
                    'description'    => null,
                    'start_date'     => '2026-02-01',
                    'end_date'       => '2026-03-15',
                    'status'         => 'gesloten',
                    'closed_at'      => $now,
                    'closed_by'      => null,
                    'created_by'     => null,
                    'created_at'     => $now,
                ]);
            }
            $rondeIds[$scope] = $rondeId;

            // Deelnemers aanmaken voor deze ronde (alleen beoordelaars met deze scope)
            $deelnemerIds[$scope] = [];
            foreach ($beoordelaars as $idx => $beo) {
                if (!in_array($scope, $beo['scopes'], true)) continue;
                $existing = db_value(
                    'SELECT id FROM scoring_deelnemers WHERE ronde_id = :r AND email = :e',
                    [':r' => $rondeId, ':e' => $beo['email']]
                );
                if ($existing) {
                    $deelnemerIds[$scope][$idx] = (int)$existing;
                    continue;
                }
                $deelnemerIds[$scope][$idx] = db_insert('scoring_deelnemers', [
                    'ronde_id'             => $rondeId,
                    'leverancier_id'       => $levId,
                    'traject_deelnemer_id' => $tdIds[$idx] ?? null,
                    'name'                 => $beo['name'],
                    'email'                => $beo['email'],
                    'token'                => bin2hex(random_bytes(32)),
                    'token_expires'        => $tokenExpiry,
                    'invited_at'           => $now,
                    'completed_at'         => $now,
                ]);
            }
        }

        // ── 5) Requirement-scores inserten ───────────────────────────────────
        // Cols: 0=Nr, 1=Scope, 6=Emma, 7=Lucas, 8=Nathalie, 9=David, 10=Sophie
        foreach ($scoresRows as $row) {
            $code  = trim((string)($row[0] ?? ''));
            $scope = strtoupper(trim((string)($row[1] ?? '')));
            $reqId = $reqByCode[$code] ?? null;
            if (!$reqId || !isset($rondeIds[$scope])) continue;

            $rondeId = $rondeIds[$scope];

            foreach ($beoordelaars as $idx => $beo) {
                $scoreVal = (int)($row[6 + $idx] ?? 0);
                if ($scoreVal < 1 || $scoreVal > 5) continue;

                $deelnemerId = $deelnemerIds[$scope][$idx] ?? null;
                if (!$deelnemerId) continue;

                $exists = db_value(
                    'SELECT id FROM scores
                      WHERE ronde_id = :r AND leverancier_id = :l
                        AND requirement_id = :q AND deelnemer_id = :d',
                    [':r' => $rondeId, ':l' => $levId, ':q' => $reqId, ':d' => $deelnemerId]
                );
                if ($exists) continue;

                db_insert('scores', [
                    'ronde_id'       => $rondeId,
                    'leverancier_id' => $levId,
                    'requirement_id' => $reqId,
                    'deelnemer_id'   => $deelnemerId,
                    'score'          => $scoreVal,
                    'notes'          => null,
                    'source'         => 'manual',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
                $nScores++;
            }
        }

        // ── 6) DEMO-ronde aanmaken ───────────────────────────────────────────
        $demoRondeId = (int)(db_value(
            "SELECT id FROM scoring_rondes
              WHERE traject_id = :t AND leverancier_id = :l AND scope = 'DEMO'",
            [':t' => $trajId, ':l' => $levId]
        ) ?: 0);

        if (!$demoRondeId) {
            $demoRondeId = db_insert('scoring_rondes', [
                'traject_id'     => $trajId,
                'leverancier_id' => $levId,
                'scope'          => 'DEMO',
                'name'           => 'DEMO-ronde',
                'description'    => null,
                'start_date'     => '2026-03-01',
                'end_date'       => '2026-03-01',
                'status'         => 'gesloten',
                'closed_at'      => $now,
                'closed_by'      => null,
                'created_by'     => null,
                'created_at'     => $now,
            ]);
        }

        // Deelnemers voor DEMO-ronde
        $demoDeelnemerIds = [];
        foreach ($beoordelaars as $idx => $beo) {
            $existing = db_value(
                'SELECT id FROM scoring_deelnemers WHERE ronde_id = :r AND email = :e',
                [':r' => $demoRondeId, ':e' => $beo['email']]
            );
            if ($existing) {
                $demoDeelnemerIds[$idx] = (int)$existing;
                continue;
            }
            $demoDeelnemerIds[$idx] = db_insert('scoring_deelnemers', [
                'ronde_id'             => $demoRondeId,
                'leverancier_id'       => $levId,
                'traject_deelnemer_id' => $tdIds[$idx] ?? null,
                'name'                 => $beo['name'],
                'email'                => $beo['email'],
                'token'                => bin2hex(random_bytes(32)),
                'token_expires'        => $tokenExpiry,
                'invited_at'           => $now,
                'completed_at'         => $now,
            ]);
        }

        // ── 7) Demo-scores (blokken 1–4) ────────────────────────────────────
        // Cols: 0=Blok, 1=Blok naam, 2=Telt mee, 3=#, 4=Vraag, 5-9=scores per beoordelaar
        foreach ($demoScoresRows as $row) {
            $vraagTekst = trim((string)($row[4] ?? ''));
            if ($vraagTekst === '') continue;
            $tdq = $tdqByText[$vraagTekst] ?? null;
            if (!$tdq) continue; // vraag niet gevonden in traject-kopie

            foreach ($beoordelaars as $idx => $beo) {
                $scoreVal = (int)($row[5 + $idx] ?? 0);
                if ($scoreVal < 1 || $scoreVal > 5) continue;

                $deelnemerId = $demoDeelnemerIds[$idx] ?? null;
                if (!$deelnemerId) continue;

                $exists = db_value(
                    'SELECT id FROM demo_scores WHERE ronde_id = :r AND question_id = :q AND deelnemer_id = :d',
                    [':r' => $demoRondeId, ':q' => $tdq['id'], ':d' => $deelnemerId]
                );
                if ($exists) continue;

                db_insert('demo_scores', [
                    'ronde_id'     => $demoRondeId,
                    'question_id'  => $tdq['id'],
                    'deelnemer_id' => $deelnemerId,
                    'score'        => $scoreVal,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
                $nDemoScores++;
            }
        }

        // ── 8) Demo open antwoorden (blok 5) ────────────────────────────────
        // Cols: 0=#, 1=Vraag, 2-6=antwoorden per beoordelaar
        foreach ($demoOpenRows as $row) {
            $vraagTekst = trim((string)($row[1] ?? ''));
            if ($vraagTekst === '') continue;
            $tdq = $tdqByText[$vraagTekst] ?? null;
            if (!$tdq) continue;

            foreach ($beoordelaars as $idx => $beo) {
                $antwoord = trim((string)($row[2 + $idx] ?? ''));
                if ($antwoord === '' || strtolower($antwoord) === 'nan') continue;

                $deelnemerId = $demoDeelnemerIds[$idx] ?? null;
                if (!$deelnemerId) continue;

                $exists = db_value(
                    'SELECT id FROM demo_open_scores WHERE ronde_id = :r AND question_id = :q AND deelnemer_id = :d',
                    [':r' => $demoRondeId, ':q' => $tdq['id'], ':d' => $deelnemerId]
                );
                if ($exists) continue;

                db_insert('demo_open_scores', [
                    'ronde_id'     => $demoRondeId,
                    'question_id'  => $tdq['id'],
                    'deelnemer_id' => $deelnemerId,
                    'answer_text'  => $antwoord,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
                $nDemoOpen++;
            }
        }
    });

    return [
        'antwoorden'  => $nAntw,
        'deelnemers'  => $nDeelnemers,
        'rondes'      => 7,   // 6 scope + 1 DEMO
        'scores'      => $nScores,
        'demo_scores' => $nDemoScores,
        'demo_open'   => $nDemoOpen,
    ];
}

/**
 * Leest alle datarijen van een sheet als genummerde arrays (0-based kolom-index).
 * Slaat de eerste $headerRows rijen over en lege rijen.
 */
function _demo_read_rows($sheet, int $headerRows = 1): array {
    $all = $sheet->toArray(null, true, true, false);
    $out = [];
    for ($i = $headerRows; $i < count($all); $i++) {
        $row = $all[$i];
        $empty = true;
        foreach ($row as $v) {
            if (trim((string)$v) !== '') { $empty = false; break; }
        }
        if (!$empty) $out[] = $row;
    }
    return $out;
}

function _demo_split_list(string $s): array {
    $parts = preg_split('/[;\n]+/', $s) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') $out[] = $p;
    }
    return $out;
}

function _demo_date_or_null(string $v, int $rn, string $sheet, string $col): ?string {
    $v = trim($v);
    if ($v === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        throw new RuntimeException("$sheet rij $rn: $col '$v' moet YYYY-MM-DD zijn.");
    }
    return $v;
}

function _demo_demo_weight(string $v, int $rn): float {
    $v = trim($v);
    if ($v === '') return 20.00;
    $n = (float)str_replace(',', '.', $v);
    if ($n < 0 || $n > 100) {
        throw new RuntimeException("Trajecten rij $rn: demo_weight_pct '$v' moet 0..100 zijn.");
    }
    return $n;
}

function _demo_read_sheet($sheet, array $expectedCols): array {
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
