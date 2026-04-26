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

    return [
        'trajecten'    => $createdTraj,
        'leveranciers' => $createdLev,
        'requirements' => $createdReq,
    ];
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
