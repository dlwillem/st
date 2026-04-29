<?php
/**
 * Rapportage — leverancier-ranking per traject.
 *
 * Totaalscore = (1 - demo%) × requirements-score + demo% × demo-score.
 * Requirements-score = Σ over hoofdcat (cat_w × Σ over sub (sub_w × avg_req_score)).
 * Demo-score         = Σ over demo-crit (crit_w × avg_demo_score).
 * Gemiddelden over deelnemers (score 0 = niet-beoordeeld, niet in avg).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/trajecten.php';
require_once __DIR__ . '/../includes/weights.php';
require_once __DIR__ . '/../includes/requirements.php';
require_once __DIR__ . '/../includes/demo_catalog.php';
require_login();

$trajectId = input_int('traject_id') ?: current_traject_id();
if ($trajectId) {
    $exists = db_value('SELECT id FROM trajecten WHERE id = :id', [':id' => $trajectId]);
    if ($exists && can_view_traject((int)$exists)) set_current_traject((int)$exists);
}
$traject = $trajectId ? db_one('SELECT * FROM trajecten WHERE id = :id', [':id' => $trajectId]) : null;

$allTrajecten = db_all('SELECT id, name, status FROM trajecten ORDER BY name');

// Auto-selecteer het eerste traject als er niets geselecteerd is
if (!$traject && !empty($allTrajecten)) {
    $trajectId = (int)$allTrajecten[0]['id'];
    if (can_view_traject($trajectId)) {
        set_current_traject($trajectId);
        $traject = db_one('SELECT * FROM trajecten WHERE id = :id', [':id' => $trajectId]);
    }
}

$pageTitle  = 'Rapportage' . ($traject ? ' — ' . $traject['name'] : '');
$currentNav = 'rapportage';

// ─── Data-aggregatie ─────────────────────────────────────────────────────────
$ranking = [];
$breakdown = []; // per leverancier: cat → sub → {weight, avg, weighted}
if ($traject) {
    $ranking = rapportage_compute_ranking((int)$trajectId);
}

/**
 * Bouwt ranking. Returned array of:
 *  - leverancier_id, leverancier_name, status
 *  - req_score, demo_score, total_score
 *  - completion (% deelnemers die afgerond hebben, overall)
 *  - breakdown: [catCode => ['name'=>..., 'weight'=>..., 'score'=>..., 'subs'=>[['name','weight','avg']]]]
 *  - demo: [['name','weight','avg']]
 */
function rapportage_compute_ranking(int $trajectId): array {
    $traject = db_one('SELECT demo_weight_pct FROM trajecten WHERE id = :id', [':id' => $trajectId]);
    $demoPct = (float)($traject['demo_weight_pct'] ?? 0);

    $leveranciers = db_all(
        'SELECT id, name, status FROM leveranciers WHERE traject_id = :t ORDER BY name',
        [':t' => $trajectId]
    );
    if (!$leveranciers) return [];

    $weights   = weights_load($trajectId);
    $structure = structure_load($trajectId);

    // Normaliseer cat-gewichten (som=100 over hoofdcat)
    $catWeights = [];
    foreach ($structure as $c) {
        $catWeights[(int)$c['id']] = (float)($weights['cats'][(int)$c['id']] ?? 0);
    }

    // Sub-weights per cat
    $subWeights = $weights['subs'];

    // Alle requirements per subcategorie (met metadata)
    $reqRows = db_all(
        'SELECT r.id, r.code, r.title, r.description, r.type, s.id AS sub_id, s.categorie_id, c.code AS cat_code
           FROM requirements r
           JOIN subcategorieen s ON s.id = r.subcategorie_id
           JOIN categorieen    c ON c.id = s.categorie_id
          WHERE r.traject_id = :t
          ORDER BY r.code',
        [':t' => $trajectId]
    );
    $reqsBySub = [];
    $reqMeta = [];
    foreach ($reqRows as $r) {
        $rid = (int)$r['id'];
        $reqsBySub[(int)$r['sub_id']][] = $rid;
        $reqMeta[$rid] = [
            'id'          => $rid,
            'code'        => (string)$r['code'],
            'title'       => (string)$r['title'],
            'description' => (string)($r['description'] ?? ''),
            'type'        => (string)$r['type'],
            'cat_code'    => (string)$r['cat_code'],
        ];
    }

    // Totaal aantal deelnemers per (leverancier × scope/categorie) — voor "X/Y gescoord"
    $totalDeelRows = db_all(
        "SELECT r.leverancier_id, r.scope, COUNT(DISTINCT d.id) AS total
           FROM scoring_rondes r
           JOIN scoring_deelnemers d ON d.ronde_id = r.id
          WHERE r.traject_id = :t
          GROUP BY r.leverancier_id, r.scope",
        [':t' => $trajectId]
    );
    $totalByLevScope = [];
    foreach ($totalDeelRows as $dt) {
        $totalByLevScope[(int)$dt['leverancier_id']][(string)$dt['scope']] = (int)$dt['total'];
    }

    // Per (lev, req) alle individuele deelnemer-scores met note — alleen menselijke beoordelingen
    $scorerRows = db_all(
        "SELECT s.leverancier_id, s.requirement_id, s.score, s.notes, d.name AS deelnemer_name
           FROM scores s
           JOIN scoring_rondes r ON r.id = s.ronde_id
           JOIN scoring_deelnemers d ON d.id = s.deelnemer_id
          WHERE r.traject_id = :t AND s.score > 0 AND s.source <> 'auto'
          ORDER BY s.leverancier_id, s.requirement_id, d.name",
        [':t' => $trajectId]
    );
    $scorersByLevReq = [];
    foreach ($scorerRows as $sr) {
        $scorersByLevReq[(int)$sr['leverancier_id']][(int)$sr['requirement_id']][] = [
            'name'  => (string)($sr['deelnemer_name'] ?? '—'),
            'score' => (float)$sr['score'],
            'note'  => (string)($sr['notes'] ?? ''),
        ];
    }

    // Leverancier-antwoorden (één per lev+req)
    $levAnsRows = db_all(
        'SELECT a.leverancier_id, a.requirement_id, a.answer_choice, a.answer_text, a.evidence_url
           FROM leverancier_answers a
          WHERE a.traject_id = :t',
        [':t' => $trajectId]
    );
    $levAnsByLevReq = [];
    foreach ($levAnsRows as $la) {
        $levAnsByLevReq[(int)$la['leverancier_id']][(int)$la['requirement_id']] = [
            'choice'       => (string)($la['answer_choice'] ?? ''),
            'text'         => (string)($la['answer_text'] ?? ''),
            'evidence_url' => (string)($la['evidence_url'] ?? ''),
        ];
    }

    // Scores per (lev, req, deelnemer) → avg per (lev, req)
    $scoreRows = db_all(
        'SELECT s.leverancier_id, s.requirement_id, AVG(s.score) AS avg_score, COUNT(*) AS n
           FROM scores s
           JOIN scoring_rondes r ON r.id = s.ronde_id
          WHERE r.traject_id = :t AND s.score > 0
          GROUP BY s.leverancier_id, s.requirement_id',
        [':t' => $trajectId]
    );
    $avgByLevReq = [];
    foreach ($scoreRows as $r) {
        $avgByLevReq[(int)$r['leverancier_id']][(int)$r['requirement_id']] = (float)$r['avg_score'];
    }

    // Demo-catalog (per-traject kopie) + blok-lookup — alleen score-vragen (blok 1-4)
    $demoQuestions = db_all(
        "SELECT id, block, sort_order, text FROM traject_demo_questions
          WHERE traject_id = :t AND active = 1 AND block < 5
          ORDER BY block, sort_order, id",
        [':t' => $trajectId]
    );
    $blockByQ = [];
    $qTextById = [];
    foreach ($demoQuestions as $q) {
        $blockByQ[(int)$q['id']]  = (int)$q['block'];
        $qTextById[(int)$q['id']] = (string)$q['text'];
    }
    // Per leverancier + vraag: gemiddelde score over alle DEMO-rondes
    $demoScoreRows = db_all(
        "SELECT r.leverancier_id, s.question_id, AVG(s.score) AS avg_score
           FROM demo_scores s
           JOIN scoring_rondes r ON r.id = s.ronde_id
          WHERE r.traject_id = :t AND r.scope = 'DEMO'
          GROUP BY r.leverancier_id, s.question_id",
        [':t' => $trajectId]
    );
    $avgDemoByLevQ = [];
    foreach ($demoScoreRows as $r) {
        $avgDemoByLevQ[(int)$r['leverancier_id']][(int)$r['question_id']] = (float)$r['avg_score'];
    }

    // Completion: % afgeronde deelnemers in alle rondes van deze leverancier
    $completionRows = db_all(
        'SELECT r.leverancier_id,
                SUM(CASE WHEN d.completed_at IS NOT NULL THEN 1 ELSE 0 END) AS done,
                COUNT(d.id) AS total
           FROM scoring_rondes r
           LEFT JOIN scoring_deelnemers d ON d.ronde_id = r.id
          WHERE r.traject_id = :t
          GROUP BY r.leverancier_id',
        [':t' => $trajectId]
    );
    $complByLev = [];
    foreach ($completionRows as $r) {
        $complByLev[(int)$r['leverancier_id']] = [
            'done'  => (int)$r['done'],
            'total' => (int)$r['total'],
        ];
    }

    $out = [];
    foreach ($leveranciers as $l) {
        $lid = (int)$l['id'];

        // Requirements-score
        $reqScore = 0.0;
        $breakdownCats = [];
        foreach ($structure as $c) {
            $catId = (int)$c['id'];
            $catW  = $catWeights[$catId] / 100.0;
            $subsOut = [];
            $catScore = 0.0;
            foreach ($c['subs'] as $s) {
                $subId = (int)$s['id'];
                $subW  = (float)($subWeights[$subId] ?? 0) / 100.0;
                $rids  = $reqsBySub[$subId] ?? [];
                $vals  = [];
                $reqsOut = [];
                foreach ($rids as $rid) {
                    $hasAvg  = isset($avgByLevReq[$lid][$rid]);
                    $avgReq  = $hasAvg ? $avgByLevReq[$lid][$rid] : null;
                    if ($hasAvg) $vals[] = $avgReq;
                    $scorers = $scorersByLevReq[$lid][$rid] ?? [];
                    $notesCount = 0;
                    foreach ($scorers as $sc) {
                        if (trim($sc['note']) !== '') $notesCount++;
                    }
                    $catCode = $reqMeta[$rid]['cat_code'] ?? '';
                    $reqsOut[] = [
                        'id'          => $rid,
                        'code'        => $reqMeta[$rid]['code'] ?? '',
                        'title'       => $reqMeta[$rid]['title'] ?? '',
                        'description' => $reqMeta[$rid]['description'] ?? '',
                        'type'        => $reqMeta[$rid]['type'] ?? '',
                        'avg'         => $avgReq,
                        'n'           => count($scorers),
                        'total'       => $totalByLevScope[$lid][$catCode] ?? 0,
                        'notes_count' => $notesCount,
                        'scorers'     => $scorers,
                        'lev_answer'  => $levAnsByLevReq[$lid][$rid] ?? null,
                    ];
                }
                $subAvg = $vals ? (array_sum($vals) / count($vals)) : 0.0;
                $subScore = $subW * $subAvg;
                $catScore += $subScore;
                $subsOut[] = [
                    'name'   => $s['name'],
                    'weight' => (float)($subWeights[$subId] ?? 0),
                    'avg'    => $subAvg,
                    'n_reqs' => count($vals),
                    'reqs'   => $reqsOut,
                ];
            }
            $reqScore += $catW * $catScore;
            $breakdownCats[$c['code']] = [
                'name'   => $c['name'],
                'code'   => $c['code'],
                'weight' => $catWeights[$catId],
                'score'  => $catScore,
                'subs'   => $subsOut,
            ];
        }

        // Demo-score: avg van blok-gemiddelden (blok 1,2,4 = totaal; blok 3 = risico)
        $blockSums = []; $blockCnts = [];
        foreach (array_keys(DEMO_BLOCKS) as $b) { $blockSums[$b] = 0.0; $blockCnts[$b] = 0; }
        $demoByBlock = [];
        foreach ($blockByQ as $qid => $b) {
            if (isset($avgDemoByLevQ[$lid][$qid])) {
                $blockSums[$b] += $avgDemoByLevQ[$lid][$qid];
                $blockCnts[$b]++;
                $demoByBlock[$b][] = [
                    'question_id' => $qid,
                    'text'        => $qTextById[$qid] ?? ('#' . $qid),
                    'avg'         => $avgDemoByLevQ[$lid][$qid],
                ];
            }
        }
        $blockAvgs = [];
        $totalSum = 0.0; $totalCnt = 0;
        $riskAvg = null;
        foreach (DEMO_BLOCKS as $b => $meta) {
            $avg = $blockCnts[$b] > 0 ? $blockSums[$b] / $blockCnts[$b] : null;
            $blockAvgs[$b] = $avg;
            if ($meta['in_total'] && $avg !== null) { $totalSum += $avg; $totalCnt++; }
            if (!$meta['in_total']) $riskAvg = $avg;
        }
        $demoScore = $totalCnt > 0 ? $totalSum / $totalCnt : 0.0;
        $riskFlag  = $riskAvg !== null && $riskAvg < DEMO_RISK_THRESHOLD;

        $total = ((100 - $demoPct) / 100.0) * $reqScore + ($demoPct / 100.0) * $demoScore;
        $compl = $complByLev[$lid] ?? ['done' => 0, 'total' => 0];

        $out[] = [
            'leverancier_id'   => $lid,
            'leverancier_name' => (string)$l['name'],
            'status'           => (string)$l['status'],
            'req_score'        => $reqScore,
            'demo_score'       => $demoScore,
            'demo_blocks'      => $blockAvgs,   // [block => ?float]
            'demo_block_qs'    => $demoByBlock, // [block => [{text,avg}]]
            'demo_risk'        => $riskAvg,
            'demo_risk_flag'   => $riskFlag,
            'total_score'      => $total,
            'completion'       => $compl,
            'breakdown_cats'   => $breakdownCats,
            'demo_pct'         => $demoPct,
        ];
    }

    usort($out, fn($a, $b) => $b['total_score'] <=> $a['total_score']);
    return $out;
}

$drillLev = input_int('lev');
$drillRow = null;
if ($drillLev) {
    foreach ($ranking as $r) { if ($r['leverancier_id'] === $drillLev) { $drillRow = $r; break; } }
}

// Hoofdcategorie-gewichten voor de methodiek-collapse (genormaliseerd naar 100%).
$methodiekCatBars = [];
if ($traject) {
    $w = weights_load($trajectId);
    $s = structure_load($trajectId);
    $rawTotal = 0.0;
    foreach ($s as $c) {
        $rawTotal += (float)($w['cats'][(int)$c['id']] ?? 0);
    }
    $demoPct = (float)($traject['demo_weight_pct'] ?? 0);
    $reqShare = max(0.0, 100.0 - $demoPct) / 100.0;
    foreach ($s as $c) {
        $raw = (float)($w['cats'][(int)$c['id']] ?? 0);
        $normInReq = $rawTotal > 0 ? ($raw / $rawTotal) * 100.0 : 0.0;
        $methodiekCatBars[] = [
            'code'   => (string)$c['code'],
            'name'   => (string)$c['name'],
            'pct'    => $normInReq * $reqShare, // aandeel in eindscore
        ];
    }
}

$bodyRenderer = function () use ($traject, $allTrajecten, $trajectId, $ranking, $drillRow, $drillLev, $methodiekCatBars) { ?>
  <div class="page-header">
    <div>
      <h1>Rapportage</h1>
      <?php if ($traject): ?>
        <p class="muted small" style="margin-top:4px;">
          Traject: <strong><?= h($traject['name']) ?></strong>
          · Demo-aandeel: <strong><?= h(number_format((float)$traject['demo_weight_pct'], 0)) ?>%</strong>
        </p>
      <?php endif; ?>
    </div>
    <div class="actions">
      <form method="get" class="row-sm" style="gap:6px;">
        <label class="muted small" for="traject_id">Traject:</label>
        <select name="traject_id" id="traject_id" class="input" onchange="this.form.submit()">
          <?php foreach ($allTrajecten as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= $trajectId == $t['id'] ? 'selected' : '' ?>>
              <?= h($t['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>

  <?php if (!$traject): ?>
    <div class="card"><p class="muted">Kies een traject om rapportage te bekijken.</p></div>
    <?php return; ?>
  <?php endif; ?>

  <?php if (!$ranking): ?>
    <div class="card"><p class="muted">Geen leveranciers in dit traject.</p></div>
    <?php return; ?>
  <?php endif; ?>

  <!-- Top-3 samenvatting-kaartjes -->
  <?php
    $podium = array_slice($ranking, 0, 3);
    $podiumColors = ['#10b981', '#0891b2', '#f59e0b']; // goud→paars→oranje accent
    $podiumLabels = ['1e plaats', '2e plaats', '3e plaats'];
  ?>
  <?php if (count($podium) >= 1): ?>
    <div class="rapport-summary" style="display:grid;grid-template-columns:repeat(<?= count($podium) ?>,1fr);gap:14px;margin-bottom:20px;">
      <?php foreach ($podium as $i => $r):
        $pos = $i + 1;
        $clr = $podiumColors[$i] ?? '#6b7280';
        $compl = $r['completion'];
        $complPct = $compl['total'] > 0 ? round(100 * $compl['done'] / $compl['total']) : 0;
      ?>
        <div class="sc podium-card" style="border-top:4px solid <?= h($clr) ?>;position:relative;overflow:hidden;">
          <div class="sc-body" style="padding:18px 20px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
              <span class="rank-badge" style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:<?= h($clr) ?>;color:#fff;font-weight:800;font-size:13px;">#<?= $pos ?></span>
              <span class="muted small" style="text-transform:uppercase;letter-spacing:.04em;font-weight:700;"><?= h($podiumLabels[$i] ?? ('#' . $pos)) ?></span>
            </div>
            <div style="font-size:18px;font-weight:700;color:#0d1117;margin-bottom:2px;"><?= h($r['leverancier_name']) ?></div>
            <div class="muted small" style="margin-bottom:10px;"><?= h(ucfirst((string)$r['status'])) ?></div>
            <div style="display:flex;align-items:baseline;gap:8px;">
              <div style="font-size:34px;font-weight:800;color:<?= h($clr) ?>;font-variant-numeric:tabular-nums;line-height:1;">
                <?= h(number_format((float)$r['total_score'], 2)) ?>
              </div>
              <div class="muted small">/ 5 totaalscore</div>
            </div>
            <div class="muted small" style="margin-top:8px;">
              Req <?= h(number_format((float)$r['req_score'], 2)) ?>
              · Demo <?= h(number_format((float)$r['demo_score'], 2)) ?>
              · <?= (int)$compl['done'] ?>/<?= (int)$compl['total'] ?> (<?= (int)$complPct ?>%) respons
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <style>
      @media (max-width: 768px) {
        .rapport-summary { grid-template-columns: 1fr !important; }
      }
    </style>
  <?php endif; ?>

  <!-- Ranking-tabel -->
  <div class="card" style="padding:0;overflow:auto;">
    <table class="table" style="margin:0;">
      <thead><tr>
        <th style="width:40px;">#</th>
        <th>Leverancier</th>
        <th class="right">Requirements</th>
        <th class="right">Demo</th>
        <th class="right" style="font-size:1rem;">Totaal</th>
        <th class="right">Respons</th>
        <th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($ranking as $i => $r):
          $pos = $i + 1;
          $posBadge = $pos === 1 ? 'green' : ($pos <= 3 ? 'indigo' : 'gray');
          $compl = $r['completion'];
          $complPct = $compl['total'] > 0 ? round(100 * $compl['done'] / $compl['total']) : 0;
          $drillHref = APP_BASE_URL . '/pages/rapportage.php?traject_id=' . (int)$trajectId
                     . '&lev=' . $r['leverancier_id'] . '#drilldown';
        ?>
          <tr>
            <td><span class="badge <?= h($posBadge) ?>"><?= $pos ?></span></td>
            <td>
              <strong><?= h($r['leverancier_name']) ?></strong>
              <div class="muted small"><?= h(ucfirst($r['status'])) ?></div>
            </td>
            <td class="right" style="font-variant-numeric:tabular-nums;"><?= h(number_format($r['req_score'], 2)) ?></td>
            <td class="right" style="font-variant-numeric:tabular-nums;">
              <?= h(number_format($r['demo_score'], 2)) ?>
              <?php if ($r['demo_risk_flag']): ?>
                <span title="Risico-indicator (blok 3) &lt; <?= h(number_format(DEMO_RISK_THRESHOLD,1)) ?>: <?= h(number_format((float)$r['demo_risk'],2)) ?>"
                      style="color:var(--amber-700);font-weight:700;">⚠</span>
              <?php endif; ?>
            </td>
            <td class="right" style="font-variant-numeric:tabular-nums;font-weight:700;font-size:1.0625rem;">
              <?= h(number_format($r['total_score'], 2)) ?>
            </td>
            <td class="right">
              <span class="muted small"><?= $compl['done'] ?>/<?= $compl['total'] ?> (<?= $complPct ?>%)</span>
            </td>
            <td class="right">
              <a class="btn sm ghost" href="<?= h($drillHref) ?>">Details</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted small" style="margin-top:8px;">
    Scores zijn gemiddelden over beoordelaars op een schaal van 1 tot 5. De totaalscore
    is een gewogen combinatie van requirements (<?= h(number_format(100-(float)$traject['demo_weight_pct'],0)) ?>%)
    en demo (<?= h(number_format((float)$traject['demo_weight_pct'],0)) ?>%).
  </p>

  <!-- Berekeningsmethodiek — klik om te openen -->
  <?php
    $catBarColors = [
      'FUNC' => '#3b82f6', 'NFR' => '#f59e0b', 'VEND' => '#10b981',
      'IMPL' => '#06b6d4', 'SUP'  => '#8b5cf6', 'LIC'  => '#ef4444',
      'DEMO' => '#ec4899',
    ];
    $demoAandeel = (float)($traject['demo_weight_pct'] ?? 0);
  ?>
  <details class="rp-methodiek" style="margin-top:20px;">
    <summary class="rp-methodiek-toggle">
      <span class="rp-mt-icon"><?= icon('info', 18) ?></span>
      <span class="rp-mt-text">
        <strong>Hoe wordt de eindscore berekend?</strong>
        <span class="muted small">Bekijk de berekeningsmethodiek — wegingen, formules en scoringsregels</span>
      </span>
      <span class="rp-mt-chev">
        <span class="rp-mt-show">Tonen</span>
        <span class="rp-mt-hide">Verbergen</span>
        <?= icon('chevron-down', 16) ?>
      </span>
    </summary>

    <div class="rp-methodiek-body">

      <!-- Categoriewegingen + Automatische scoringsregels: side-by-side -->
      <div class="rp-methodiek-row">
        <div class="rp-methodiek-panel">
          <h3 class="rp-mp-title">
            <span class="rp-mp-ico" style="color:#0891b2;"><?= icon('sliders', 16) ?></span>
            Categoriewegingen
          </h3>
          <div class="rp-bars">
            <?php foreach ($methodiekCatBars as $b):
              $code = $b['code']; $pct = (float)$b['pct'];
              $clr  = $catBarColors[$code] ?? '#6b7280';
            ?>
              <div class="rp-bar">
                <span class="rp-bar-label" style="background:<?= h($clr) ?>22;color:<?= h($clr) ?>;"><?= h($code) ?></span>
                <div class="rp-bar-track">
                  <span class="rp-bar-fill" style="width:<?= h(max(0, min(100, $pct))) ?>%;background:<?= h($clr) ?>;"></span>
                </div>
                <span class="rp-bar-pct" style="color:<?= h($clr) ?>;"><?= h(number_format($pct, 0)) ?>%</span>
              </div>
            <?php endforeach; ?>
            <?php if ($demoAandeel > 0): ?>
              <div class="rp-bar">
                <span class="rp-bar-label" style="background:<?= h($catBarColors['DEMO']) ?>22;color:<?= h($catBarColors['DEMO']) ?>;">DEMO</span>
                <div class="rp-bar-track">
                  <span class="rp-bar-fill" style="width:<?= h($demoAandeel) ?>%;background:<?= h($catBarColors['DEMO']) ?>;"></span>
                </div>
                <span class="rp-bar-pct" style="color:<?= h($catBarColors['DEMO']) ?>;"><?= h(number_format($demoAandeel, 0)) ?>%</span>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="rp-methodiek-panel">
          <h3 class="rp-mp-title">
            <span class="rp-mp-ico" style="color:#f59e0b;"><?= icon('check', 16) ?></span>
            Automatische scoringsregels
          </h3>
          <ul class="rp-rules">
            <li><span>Ja — zonder toelichting</span><strong style="color:#10b981;">5 (max)</strong></li>
            <li><span>Nee — zonder toelichting</span><strong style="color:#ef4444;">1 (min)</strong></li>
            <li><span>Deels / met toelichting</span><strong style="color:#f59e0b;">Handmatig</strong></li>
            <li><span>Knock-out met "Nee"</span><strong style="color:#ef4444;">Onder review</strong></li>
          </ul>
        </div>
      </div>

      <!-- Eindscoreformule -->
      <div class="rp-formula">
        <div class="rp-formula-label">EINDSCOREFORMULE</div>
        <pre class="rp-formula-code">Eindscore       = &sum; (categorie_gewicht &times; categorie_gemiddelde)
Categorie_gem.  = &sum; (requirement_score) &divide; aantal_requirements
<span class="rp-formula-dim">// Demo-score telt apart mee: eindscore &times; (1 &minus; demo_aandeel) + demo_gem &times; demo_aandeel</span></pre>
      </div>

      <!-- Bestaand voorbeeld: 3 niveaus -->
      <div class="rp-method-grid">
      <!-- Niveau 1 -->
      <div class="rp-level">
        <div class="rp-level-head">
          <span class="rp-level-num" style="background:#0891b2;">1</span>
          <div>
            <div class="rp-level-title">Van scores naar subcategoriescore</div>
            <div class="muted small">
              Beoordelaars scoren elke requirement op 1–5. Scores worden eerst gemiddeld over alle
              beoordelaars. Daarna wordt een gewogen gemiddelde berekend waarbij
              <strong>Must 2×</strong> telt en <strong>Should 1×</strong>.
            </div>
          </div>
        </div>

        <div class="table-wrap" style="margin-top:14px;">
          <table class="table" style="margin:0;">
            <thead><tr>
              <th>Requirement</th>
              <th>MoSCoW</th>
              <th class="right">Gem. score</th>
              <th class="right">Gewicht</th>
            </tr></thead>
            <tbody>
              <tr><td>HLR-02 Configureerbare variabelen</td><td><span class="badge indigo">Must</span></td><td class="right">3.7</td><td class="right">2</td></tr>
              <tr><td>HLR-03 Component vervanging</td><td><span class="badge indigo">Must</span></td><td class="right">4.3</td><td class="right">2</td></tr>
              <tr><td>HLR-05 Catalogusimport</td><td><span class="badge gray">Should</span></td><td class="right">3.0</td><td class="right">1</td></tr>
            </tbody>
          </table>
        </div>
        <pre class="rp-calc">(3.7×2) + (4.3×2) + (3.0×1)  =  19.0  ÷  5  =  <strong>3.80</strong></pre>
      </div>

      <!-- Niveau 2 -->
      <div class="rp-level">
        <div class="rp-level-head">
          <span class="rp-level-num" style="background:#3b82f6;">2</span>
          <div>
            <div class="rp-level-title">Van subcategoriescore naar hoofdcategoriescore</div>
            <div class="muted small">
              Binnen een hoofdcategorie telt elke subcategorie mee volgens het ingestelde gewicht (samen 100%).
            </div>
          </div>
        </div>
        <pre class="rp-calc">L-9.1  score 4.2  × 30%  =  1.26
L-9.2  score 3.8  × 20%  =  0.76
L-9.3  score 3.1  × 50%  =  1.55
                              ────
                              <strong>3.57</strong></pre>
      </div>

      <!-- Niveau 3 -->
      <div class="rp-level">
        <div class="rp-level-head">
          <span class="rp-level-num" style="background:#10b981;">3</span>
          <div>
            <div class="rp-level-title">Van hoofdcategoriescore naar eindscore</div>
            <div class="muted small">
              Over alle scopes wordt opnieuw een gewogen gemiddelde genomen volgens de traject-weging.
            </div>
          </div>
        </div>
        <pre class="rp-calc">Functioneel       3.57  × 35%  =  1.25
Non-functioneel   4.10  × 15%  =  0.62
Leverancier       3.80  × 20%  =  0.76
Licentiemodel     3.20  × 10%  =  0.32
Support           3.60  × 20%  =  0.72
                                  ────
Eindscore                         <strong>3.67</strong>  →  67 / 100</pre>
      </div>

      <!-- KO-noot -->
      <div class="rp-ko-note">
        <span class="rp-ko-ico">⚠️</span>
        <div>
          <strong>KO-requirements vallen buiten deze berekening.</strong>
          Scoort een leverancier gemiddeld ≤ 2 op een KO-requirement, dan verschijnt
          een ⚠️ naast de eindscore — ongeacht hoe hoog die is.
        </div>
      </div>

      <!-- Niet-ingevuld-noot -->
      <div class="rp-empty-note">
        <span class="rp-empty-ico">∅</span>
        <div>
          <strong>Niet-ingevulde scores tellen niet mee.</strong>
          Laat een beoordelaar een requirement leeg, dan wordt die beoordelaar
          overgeslagen in het gemiddelde van dat requirement. Heeft <em>niemand</em>
          het requirement gescoord, dan telt het ook niet mee in de weging van de
          subcategorie — de resterende requirements bepalen dan de subcategoriescore.
        </div>
      </div>

      <p class="rp-transp">
        <strong>Transparantie:</strong> Alle wegingen zijn instelbaar per traject via de Weging-tab.
        Beoordelaars scoren onafhankelijk van elkaar; de eindscore is het gemiddelde van alle ingediende scores.
      </p>
    </div>
    </div>
  </details>

  <style>
    /* Collapsible methodiek */
    .rp-methodiek { border-radius: 12px; }
    .rp-methodiek > summary { list-style: none; cursor: pointer; }
    .rp-methodiek > summary::-webkit-details-marker { display: none; }
    .rp-methodiek-toggle {
      display: flex; align-items: center; gap: 14px;
      background: #fff; border: 1px solid var(--border,#e5e7eb);
      border-radius: 12px; padding: 16px 20px;
      transition: background .15s, border-color .15s;
    }
    .rp-methodiek-toggle:hover { background: #f9fafb; border-color: #d1d5db; }
    .rp-mt-icon {
      display: inline-flex; align-items: center; justify-content: center;
      width: 36px; height: 36px; border-radius: 10px;
      background: #ecfeff; color: #0891b2; flex-shrink: 0;
    }
    .rp-mt-text { display: flex; flex-direction: column; flex: 1; gap: 2px; }
    .rp-mt-text strong { font-size: 15px; color: #0d1117; font-weight: 700; }
    .rp-mt-chev {
      display: inline-flex; align-items: center; gap: 6px;
      color: #0891b2; font-weight: 600; font-size: 13px; flex-shrink: 0;
    }
    .rp-mt-chev svg { transition: transform .2s; }
    .rp-methodiek[open] .rp-mt-chev svg { transform: rotate(180deg); }
    .rp-mt-hide { display: none; }
    .rp-methodiek[open] .rp-mt-show { display: none; }
    .rp-methodiek[open] .rp-mt-hide { display: inline; }
    .rp-methodiek[open] .rp-methodiek-toggle {
      border-bottom-left-radius: 0; border-bottom-right-radius: 0;
      border-bottom-color: transparent;
    }
    .rp-methodiek-body {
      background: #fff; border: 1px solid var(--border,#e5e7eb);
      border-top: none; border-radius: 0 0 12px 12px;
      padding: 24px 32px 28px;
    }

    /* Twee-koloms panels */
    .rp-methodiek-row {
      display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
      margin-bottom: 18px;
    }
    @media (max-width: 820px) { .rp-methodiek-row { grid-template-columns: 1fr; } }
    .rp-methodiek-panel {
      border: 1px solid var(--border,#e5e7eb); border-radius: 12px;
      padding: 18px 20px;
    }
    .rp-mp-title {
      display: flex; align-items: center; gap: 8px;
      margin: 0 0 14px; font-size: 15px; font-weight: 700; color: #0d1117;
    }
    .rp-mp-ico { display: inline-flex; }

    /* Gekleurde weging-balkjes */
    .rp-bars { display: flex; flex-direction: column; gap: 10px; }
    .rp-bar { display: grid; grid-template-columns: 60px 1fr 48px; align-items: center; gap: 12px; }
    .rp-bar-label {
      font-size: 11px; font-weight: 700; letter-spacing: .04em;
      padding: 4px 8px; border-radius: 6px; text-align: center;
    }
    .rp-bar-track { height: 8px; background: #f3f4f6; border-radius: 999px; overflow: hidden; }
    .rp-bar-fill { display: block; height: 100%; border-radius: 999px; transition: width .3s; }
    .rp-bar-pct { font-size: 13px; font-weight: 700; text-align: right; font-variant-numeric: tabular-nums; }

    /* Scoringsregels */
    .rp-rules { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
    .rp-rules li {
      display: flex; justify-content: space-between; align-items: center;
      font-size: 14px; color: #374151;
    }
    .rp-rules li strong { font-weight: 700; font-size: 13px; }

    /* Eindscoreformule */
    .rp-formula {
      background: #0d1117; color: #e5e7eb;
      border-radius: 12px; padding: 18px 20px; margin: 0 0 22px;
    }
    .rp-formula-label {
      font-size: 11px; font-weight: 700; letter-spacing: .08em;
      color: #9ca3af; margin-bottom: 10px;
    }
    .rp-formula-code {
      margin: 0; font-size: 13px; line-height: 1.7;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      background: none; color: inherit; white-space: pre-wrap;
    }
    .rp-formula-dim { color: #6b7280; }
    .rp-transp {
      margin: 18px 0 0; font-size: 13px; line-height: 1.6; color: #4b5563;
    }

    .rp-method-head { margin-bottom: 18px; }
    .rp-method-grid { display: flex; flex-direction: column; gap: 22px; }
    .rp-level {
      border-left: 3px solid var(--border, #e5e7eb);
      padding: 4px 0 4px 18px;
    }
    .rp-level-head { display: flex; gap: 14px; align-items: flex-start; }
    .rp-level-num {
      flex-shrink: 0;
      width: 28px; height: 28px; border-radius: 50%;
      color: #fff; font-weight: 800; font-size: 13px;
      display: inline-flex; align-items: center; justify-content: center;
      margin-top: 2px;
    }
    .rp-level-title { font-size: 15px; font-weight: 700; color: #0d1117; margin-bottom: 2px; }
    .rp-calc {
      background: #0d1117; color: #e5e7eb;
      padding: 14px 16px; border-radius: 10px;
      font-size: 12.5px; line-height: 1.55;
      overflow: auto; margin: 14px 0 0;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    }
    .rp-calc strong { color: #67e8f9; font-weight: 800; }
    .rp-ko-note {
      display: flex; gap: 12px; align-items: flex-start;
      background: #fef3c7; border: 1px solid #fcd34d;
      border-radius: 10px; padding: 12px 14px;
      font-size: 13px; line-height: 1.55; color: #78350f;
    }
    .rp-ko-ico { font-size: 18px; line-height: 1; flex-shrink: 0; }
    .rp-empty-note {
      display: flex; gap: 12px; align-items: flex-start;
      background: #ecfeff; border: 1px solid #a5f3fc;
      border-radius: 10px; padding: 12px 14px;
      font-size: 13px; line-height: 1.55; color: #155e75;
      margin-top: 10px;
    }
    .rp-empty-ico { font-size: 18px; line-height: 1; flex-shrink: 0; font-weight: 800; }
  </style>

  <?php if ($drillRow): ?>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('drilldown');
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    </script>
    <div id="drilldown" class="card" style="margin-top:16px;scroll-margin-top:20px;">
      <div class="card-title">
        <h2>Breakdown — <?= h($drillRow['leverancier_name']) ?></h2>
        <a class="btn sm ghost" href="<?= h(APP_BASE_URL) ?>/pages/rapportage.php?traject_id=<?= (int)$trajectId ?>">
          <?= icon('x', 12) ?> Sluiten
        </a>
      </div>

      <h3 style="margin:12px 0 6px;font-size:0.9375rem;">Requirements per hoofdcategorie</h3>
      <div class="table-wrap">
        <table class="table">
          <thead><tr>
            <th>Hoofdcategorie</th>
            <th class="right">Gewicht</th>
            <th class="right">Score</th>
          </tr></thead>
          <tbody>
            <?php foreach ($drillRow['breakdown_cats'] as $code => $c):
              $st = requirement_cat_style($code);
            ?>
              <tr>
                <td>
                  <span class="badge <?= h($st['color']) ?>"><?= h($code) ?></span>
                  <?= h($c['name']) ?>
                </td>
                <td class="right"><?= h(number_format($c['weight'], 1)) ?>%</td>
                <td class="right" style="font-variant-numeric:tabular-nums;"><?= h(number_format($c['score'], 2)) ?></td>
              </tr>
              <?php foreach ($c['subs'] as $s): ?>
                <tr>
                  <td style="padding-left:32px;" class="muted small">↳ <?= h($s['name']) ?>
                    <?php if ($s['n_reqs'] === 0): ?>
                      <span class="badge gray">geen scores</span>
                    <?php endif; ?>
                  </td>
                  <td class="right muted small"><?= h(number_format($s['weight'], 1)) ?>%</td>
                  <td class="right muted small"><?= h(number_format($s['avg'], 2)) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <h3 style="margin:16px 0 6px;font-size:0.9375rem;">Requirements — scores &amp; toelichtingen</h3>
      <p class="muted small" style="margin:0 0 10px;">
        Klik een requirement open om het antwoord van de leverancier en de toelichtingen van alle beoordelaars te zien.
      </p>
      <?php
        $choiceLabel = ['volledig' => 'Ja', 'deels' => 'Deels', 'niet' => 'Nee', 'nvt' => 'N.v.t.'];
        $catTint = [
          'FUNC' => ['bg' => '#eff6ff', 'pill_bg' => '#dbeafe', 'pill_fg' => '#1d4ed8'],
          'NFR'  => ['bg' => '#fef3c7', 'pill_bg' => '#fde68a', 'pill_fg' => '#92400e'],
          'VEND' => ['bg' => '#ecfdf5', 'pill_bg' => '#a7f3d0', 'pill_fg' => '#047857'],
          'IMPL' => ['bg' => '#ecfeff', 'pill_bg' => '#a5f3fc', 'pill_fg' => '#0e7490'],
          'LIC'  => ['bg' => '#fef2f2', 'pill_bg' => '#fecaca', 'pill_fg' => '#b91c1c'],
          'SUP'  => ['bg' => '#f5f3ff', 'pill_bg' => '#ddd6fe', 'pill_fg' => '#6d28d9'],
        ];
        // Kleurt een score groen/oranje/rood afhankelijk van de waarde
        $scoreColor = function (?float $v): string {
          if ($v === null) return '#9ca3af';
          if ($v >= 4) return '#10b981';
          if ($v >= 2.5) return '#f59e0b';
          return '#ef4444';
        };
        $totalDeelnemersLev = $drillRow['completion']['total'] ?? 0;
      ?>
      <div class="rp-reqs">
        <?php foreach ($drillRow['breakdown_cats'] as $code => $c):
          $hasAnyReq = false;
          foreach ($c['subs'] as $s) { if (!empty($s['reqs'])) { $hasAnyReq = true; break; } }
          if (!$hasAnyReq) continue;

          // ── Per-categorie statistieken ──────────────────────────────────
          $catReqTotal    = 0;
          $catReqFilled   = 0;            // reqs met ≥ 1 score
          $catScoreSum    = 0.0;
          $catScoreCnt    = 0;
          $catKoFails     = 0;
          $catScorerNames = [];           // distinct deelnemers die scoorden
          $catTotDeel     = 0;            // totaal beoordelaars voor deze scope
          foreach ($c['subs'] as $s) {
            foreach ($s['reqs'] as $rq) {
              $catReqTotal++;
              if ($rq['n'] > 0) $catReqFilled++;
              if ($rq['total'] > $catTotDeel) $catTotDeel = (int)$rq['total'];
              foreach ($rq['scorers'] as $sc) {
                $catScoreSum += (float)$sc['score'];
                $catScoreCnt++;
                $catScorerNames[$sc['name']] = true;
              }
              if ($rq['type'] === 'ko' && $rq['avg'] !== null && (float)$rq['avg'] <= 2) {
                $catKoFails++;
              }
            }
          }
          $catAvg = $catScoreCnt > 0 ? ($catScoreSum / $catScoreCnt) : null;
          $catScorersDone = count($catScorerNames);
          $tint = $catTint[$code] ?? ['bg' => '#f3f4f6', 'pill_bg' => '#e5e7eb', 'pill_fg' => '#374151'];
        ?>
          <details class="rp-cat" open>
            <summary style="background:<?= h($tint['bg']) ?>;">
              <span class="rp-cat-pill" style="background:<?= h($tint['pill_bg']) ?>;color:<?= h($tint['pill_fg']) ?>;"><?= h($code) ?></span>
              <strong class="rp-cat-name"><?= h($c['name']) ?></strong>
              <span class="rp-cat-meta">
                <span class="muted small"><?= (int)$catReqFilled ?>/<?= (int)$catReqTotal ?> ingevuld</span>
                <span class="rp-cat-avg" style="color:<?= h($scoreColor($catAvg)) ?>;">
                  gem. <?= $catAvg !== null ? h(number_format($catAvg, 1)) : '—' ?>
                </span>
              </span>
            </summary>

            <!-- Statistiek-strip per hoofdcategorie -->
            <div class="rp-stats">
              <div class="rp-stat">
                <div class="rp-stat-num" style="color:#0891b2;"><?= (int)$catScorersDone ?>/<?= (int)$catTotDeel ?></div>
                <div class="rp-stat-lbl"><strong>Beoordelaars</strong><span class="muted small">hebben gescoord</span></div>
              </div>
              <div class="rp-stat">
                <div class="rp-stat-num" style="color:#3b82f6;"><?= (int)$catReqFilled ?>/<?= (int)$catReqTotal ?></div>
                <div class="rp-stat-lbl"><strong>Requirements</strong><span class="muted small">zijn ingevuld</span></div>
              </div>
              <div class="rp-stat">
                <div class="rp-stat-num" style="color:<?= h($scoreColor($catAvg)) ?>;"><?= $catAvg !== null ? h(number_format($catAvg, 1)) : '—' ?></div>
                <div class="rp-stat-lbl"><strong>Gemiddelde score</strong><span class="muted small">op schaal van 1–5</span></div>
              </div>
              <div class="rp-stat">
                <div class="rp-stat-num" style="color:<?= $catKoFails > 0 ? '#ef4444' : '#9ca3af' ?>;"><?= (int)$catKoFails ?></div>
                <div class="rp-stat-lbl"><strong>Knock-outs</strong><span class="muted small">met score ≤ 2</span></div>
              </div>
            </div>

            <?php foreach ($c['subs'] as $s): if (empty($s['reqs'])) continue;
              $subFilled = 0; foreach ($s['reqs'] as $rqq) if ($rqq['n'] > 0) $subFilled++;
              $subTotal  = count($s['reqs']);
            ?>
              <div class="rp-sub">
                <div class="rp-sub-head">
                  <span class="rp-sub-name"><?= h($s['name']) ?></span>
                  <span class="rp-sub-meta">
                    <span class="muted small"><?= (int)$subFilled ?>/<?= (int)$subTotal ?> gescoord</span>
                    <span class="rp-sub-avg" style="color:<?= h($scoreColor($s['avg'] > 0 ? (float)$s['avg'] : null)) ?>;">
                      gem. <?= $s['avg'] > 0 ? h(number_format((float)$s['avg'], 1)) : '—' ?>
                    </span>
                  </span>
                </div>
                <?php foreach ($s['reqs'] as $rq):
                  $avg     = $rq['avg'] !== null ? (float)$rq['avg'] : null;
                  $avgDisp = $avg !== null ? number_format($avg, 1) : '—';
                  $avgClr  = $scoreColor($avg);
                  $isKo    = ($rq['type'] === 'ko');
                  $la      = $rq['lev_answer'];
                  $n       = (int)$rq['n'];
                  $tot     = (int)$rq['total'];
                  $hasNt   = $rq['notes_count'] > 0;
                  $choiceKey = $la['choice'] ?? '';
                  $cLabel    = $choiceKey !== '' ? ($choiceLabel[$choiceKey] ?? $choiceKey) : '';
                ?>
                  <details class="rp-req">
                    <summary>
                      <div class="rp-req-left">
                        <div class="rp-req-title-line">
                          <strong class="rp-req-title"><?= h($rq['title']) ?></strong>
                          <?php if ($isKo): ?><span class="rp-ko-tag">KNOCK-OUT</span><?php endif; ?>
                        </div>
                        <?php if (trim((string)$rq['description']) !== ''): ?>
                          <div class="rp-req-sub"><?= h($rq['description']) ?></div>
                        <?php endif; ?>
                      </div>
                      <div class="rp-req-right">
                        <?php if ($cLabel !== ''): ?>
                          <span class="rp-choice rp-choice-<?= h($choiceKey) ?>"><?= h($cLabel) ?></span>
                        <?php else: ?>
                          <span class="rp-choice-empty muted small">—</span>
                        <?php endif; ?>
                        <span class="rp-req-progress"><?= $tot > 0 ? "{$n}/{$tot}" : ($n > 0 ? (string)$n : '0/0') ?></span>
                        <span class="rp-req-avg" style="color:<?= h($avgClr) ?>;"><?= h($avgDisp) ?></span>
                        <?php if ($hasNt): ?>
                          <span class="rp-chip" title="<?= (int)$rq['notes_count'] ?> toelichting<?= $rq['notes_count'] === 1 ? '' : 'en' ?>">💬 <?= (int)$rq['notes_count'] ?></span>
                        <?php endif; ?>
                        <span class="rp-req-chev">▾</span>
                        <code class="rp-req-code"><?= h($rq['code']) ?></code>
                      </div>
                    </summary>
                    <div class="rp-req-body">
                      <?php if ($la): ?>
                        <div class="rp-lev-ans">
                          <div class="rp-lev-head">
                            <strong>Antwoord leverancier</strong>
                            <?php if ($cLabel !== ''): ?>
                              <span class="rp-choice rp-choice-<?= h($choiceKey) ?>"><?= h($cLabel) ?></span>
                            <?php endif; ?>
                          </div>
                          <?php if (trim($la['text']) !== ''): ?>
                            <div class="rp-lev-text">"<?= nl2br(h($la['text'])) ?>"</div>
                          <?php else: ?>
                            <div class="muted small" style="font-style:italic;">Geen toelichting</div>
                          <?php endif; ?>
                          <?php $ev = safe_url($la['evidence_url'] ?? null); if ($ev !== ''): ?>
                            <div class="muted small" style="margin-top:4px;">
                              <a href="<?= h($ev) ?>" target="_blank" rel="noopener noreferrer">Bewijs ↗</a>
                            </div>
                          <?php endif; ?>

                          <?php if (!empty($rq['scorers'])): ?>
                            <div class="rp-scorers-sep"></div>
                            <table class="table rp-scorers-tbl">
                              <thead><tr>
                                <th>Beoordelaar</th>
                                <th class="right" style="width:70px;">Score</th>
                                <th>Toelichting</th>
                              </tr></thead>
                              <tbody>
                                <?php foreach ($rq['scorers'] as $sc): ?>
                                  <tr>
                                    <td class="small"><?= h($sc['name']) ?></td>
                                    <td class="right small" style="font-variant-numeric:tabular-nums;color:<?= h($scoreColor((float)$sc['score'])) ?>;font-weight:700;"><?= h(number_format($sc['score'], 1)) ?></td>
                                    <td class="small">
                                      <?php if (trim($sc['note']) !== ''): ?>
                                        <?= nl2br(h($sc['note'])) ?>
                                      <?php else: ?>
                                        <span class="muted">—</span>
                                      <?php endif; ?>
                                    </td>
                                  </tr>
                                <?php endforeach; ?>
                              </tbody>
                            </table>
                          <?php else: ?>
                            <div class="muted small" style="margin-top:8px;">Nog geen beoordelingen voor dit requirement</div>
                          <?php endif; ?>
                        </div>
                      <?php else: ?>
                        <div class="rp-lev-ans">
                          <div class="muted small" style="font-style:italic;">Leverancier heeft dit requirement niet ingevuld.</div>
                          <?php if (!empty($rq['scorers'])): ?>
                            <div class="rp-scorers-sep"></div>
                            <table class="table rp-scorers-tbl">
                              <thead><tr>
                                <th>Beoordelaar</th>
                                <th class="right" style="width:70px;">Score</th>
                                <th>Toelichting</th>
                              </tr></thead>
                              <tbody>
                                <?php foreach ($rq['scorers'] as $sc): ?>
                                  <tr>
                                    <td class="small"><?= h($sc['name']) ?></td>
                                    <td class="right small" style="font-variant-numeric:tabular-nums;color:<?= h($scoreColor((float)$sc['score'])) ?>;font-weight:700;"><?= h(number_format($sc['score'], 1)) ?></td>
                                    <td class="small">
                                      <?php if (trim($sc['note']) !== ''): ?>
                                        <?= nl2br(h($sc['note'])) ?>
                                      <?php else: ?>
                                        <span class="muted">—</span>
                                      <?php endif; ?>
                                    </td>
                                  </tr>
                                <?php endforeach; ?>
                              </tbody>
                            </table>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </details>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </details>
        <?php endforeach; ?>
      </div>

      <style>
        .rp-reqs { display: flex; flex-direction: column; gap: 16px; margin-bottom: 18px; }
        .rp-cat {
          border: 1px solid var(--border, #e5e7eb);
          border-radius: 12px; background: #fff; overflow: hidden;
        }
        .rp-cat > summary {
          list-style: none; cursor: pointer;
          padding: 12px 18px;
          display: flex; align-items: center; gap: 12px;
          font-size: 14px;
        }
        .rp-cat > summary::-webkit-details-marker { display: none; }
        .rp-cat-pill {
          font-weight: 700; font-size: 11px; letter-spacing: .06em;
          padding: 3px 10px; border-radius: 6px;
        }
        .rp-cat-name { font-size: 15px; color: #0d1117; flex: 1; }
        .rp-cat-meta { display: inline-flex; gap: 18px; align-items: baseline; }
        .rp-cat-avg { font-weight: 700; font-variant-numeric: tabular-nums; }

        /* Stats-strip per categorie */
        .rp-stats {
          display: grid; grid-template-columns: repeat(4, 1fr);
          gap: 12px; padding: 14px 18px;
          border-bottom: 1px solid var(--border, #e5e7eb);
          background: #fff;
        }
        .rp-stat { display: flex; align-items: center; gap: 10px; }
        .rp-stat-num { font-size: 26px; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .rp-stat-lbl { display: flex; flex-direction: column; font-size: 13px; color: #0d1117; line-height: 1.2; }
        .rp-stat-lbl .muted { font-size: 11px; }

        .rp-sub { padding: 0 18px; }
        .rp-sub-head {
          display: flex; justify-content: space-between; align-items: baseline;
          padding: 10px 0 6px; border-bottom: 1px solid #f1f5f9;
        }
        .rp-sub-name { font-size: 13px; color: #475569; }
        .rp-sub-meta { display: inline-flex; gap: 14px; align-items: baseline; font-size: 12px; }
        .rp-sub-avg { font-weight: 700; font-variant-numeric: tabular-nums; }

        .rp-req { border-bottom: 1px solid #f1f5f9; }
        .rp-req:last-child { border-bottom: 0; }
        .rp-req > summary {
          list-style: none; cursor: pointer;
          padding: 12px 0;
          display: flex; align-items: center; gap: 16px;
          font-size: 14px;
        }
        .rp-req > summary::-webkit-details-marker { display: none; }
        .rp-req-left { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
        .rp-req-title-line { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .rp-req-title { font-weight: 700; color: #0d1117; font-size: 14px; }
        .rp-req-sub { font-size: 12.5px; color: #6b7280; line-height: 1.4; }
        .rp-ko-tag { color: #dc2626; font-weight: 800; font-size: 11px; letter-spacing: .08em; }
        .rp-req-right {
          display: inline-flex; align-items: center; gap: 10px;
          flex-shrink: 0; font-size: 12.5px;
        }
        .rp-req-progress { color: #6b7280; font-variant-numeric: tabular-nums; min-width: 32px; text-align: center; }
        .rp-req-avg {
          font-weight: 700; font-variant-numeric: tabular-nums;
          font-size: 16px; min-width: 36px; text-align: right;
        }
        .rp-req-chev { color: #9ca3af; transition: transform .15s; display: inline-block; width: 10px; text-align: center; }
        .rp-req[open] .rp-req-chev { transform: rotate(180deg); }
        .rp-req-code {
          font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
          font-size: 11px; color: #9ca3af; min-width: 48px; text-align: right;
        }
        .rp-chip {
          background: #ecfeff; color: #155e75; border: 1px solid #a5f3fc;
          padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700;
          white-space: nowrap;
        }
        .rp-choice {
          display: inline-flex; align-items: center; justify-content: center;
          font-weight: 700; font-size: 11px;
          padding: 3px 12px; border-radius: 999px; letter-spacing: .02em;
          min-width: 54px;
        }
        .rp-choice-volledig { background: #d1fae5; color: #047857; }
        .rp-choice-deels    { background: #fef3c7; color: #92400e; }
        .rp-choice-niet     { background: #fee2e2; color: #b91c1c; }
        .rp-choice-nvt      { background: #e5e7eb; color: #4b5563; }
        .rp-choice-empty    { min-width: 54px; text-align: center; }

        .rp-req-body { padding: 2px 0 14px 0; }
        .rp-lev-ans {
          background: #f8fafc; border: 1px solid var(--border, #e5e7eb);
          border-radius: 10px; padding: 14px 16px;
        }
        .rp-lev-head { display: flex; gap: 10px; align-items: center; margin-bottom: 8px; font-size: 13px; }
        .rp-lev-text { font-size: 13.5px; color: #1f2937; font-style: italic; white-space: pre-wrap; }
        .rp-scorers-sep {
          height: 1px; background: #e5e7eb; margin: 14px -16px;
        }
        .rp-scorers-tbl { margin: 0 !important; }

        @media (max-width: 720px) {
          .rp-stats { grid-template-columns: repeat(2, 1fr); }
          .rp-req > summary { flex-wrap: wrap; }
          .rp-req-right { margin-left: auto; }
        }
      </style>

      <?php
        $demoCompl = db_one(
            'SELECT COALESCE(SUM(CASE WHEN d.completed_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS done,
                    COUNT(d.id) AS total
               FROM scoring_rondes r
               LEFT JOIN scoring_deelnemers d ON d.ronde_id = r.id
              WHERE r.traject_id = :t AND r.scope = \'DEMO\' AND r.leverancier_id = :l',
            [':t' => $trajectId, ':l' => $drillLev]
        );
        $demoDone  = (int)($demoCompl['done']  ?? 0);
        $demoTotal = (int)($demoCompl['total'] ?? 0);
        $demoAvg   = $drillRow['demo_score'] > 0 ? (float)$drillRow['demo_score'] : null;
        $demoTint  = ['bg' => '#eff6ff', 'pill_bg' => '#dbeafe', 'pill_fg' => '#1d4ed8'];
      ?>
      <details class="rp-cat" open style="margin-top:16px;">
        <summary style="background:<?= h($demoTint['bg']) ?>;">
          <span class="rp-cat-pill" style="background:<?= h($demoTint['pill_bg']) ?>;color:<?= h($demoTint['pill_fg']) ?>;">DEMO</span>
          <strong class="rp-cat-name">Demo</strong>
          <span class="rp-cat-meta">
            <span class="rp-cat-avg" style="color:<?= h($scoreColor($demoAvg)) ?>;">
              gem. <?= $demoAvg !== null ? h(number_format($demoAvg, 1)) : '—' ?>
            </span>
          </span>
        </summary>

        <div class="rp-stats">
          <div class="rp-stat">
            <div class="rp-stat-num" style="color:#0891b2;"><?= $demoDone ?>/<?= $demoTotal ?></div>
            <div class="rp-stat-lbl"><strong>Beoordelaars</strong><span class="muted small">hebben gescoord</span></div>
          </div>
          <div class="rp-stat">
            <div class="rp-stat-num" style="color:<?= h($scoreColor($demoAvg)) ?>;"><?= $demoAvg !== null ? h(number_format($demoAvg, 1)) : '—' ?></div>
            <div class="rp-stat-lbl"><strong>Gemiddelde score</strong><span class="muted small">op schaal van 1–5</span></div>
          </div>
        </div>

      <div class="table-wrap">
        <table class="table">
          <thead><tr>
            <th>Blok</th>
            <th>Vragen</th>
            <th class="right">Rol</th>
            <th class="right">Gem. score</th>
          </tr></thead>
          <tbody>
            <?php foreach (DEMO_BLOCKS as $b => $meta):
              if (demo_block_is_open($b)) continue; // Blok 5 (open vragen) heeft geen score — zie sectie hieronder
              $avg  = $drillRow['demo_blocks'][$b] ?? null;
              $qs   = $drillRow['demo_block_qs'][$b] ?? [];
              $bcol = $meta['in_total'] ? 'blue' : 'amber';
            ?>
              <tr>
                <td>
                  <span class="badge <?= h($bcol) ?>">Blok <?= (int)$b ?></span>
                  <strong><?= h($meta['title']) ?></strong>
                </td>
                <td class="muted small"><?= count($qs) ?></td>
                <td class="right">
                  <?php if ($meta['in_total']): ?>
                    <span class="muted small">telt mee in totaal</span>
                  <?php else: ?>
                    <span class="badge amber" style="font-size:0.7rem;">Risico-indicator</span>
                  <?php endif; ?>
                </td>
                <td class="right" style="font-variant-numeric:tabular-nums;">
                  <?= $avg !== null ? h(number_format((float)$avg, 2)) : '<span class="muted small">—</span>' ?>
                  <?php if (!$meta['in_total'] && $avg !== null && $avg < DEMO_RISK_THRESHOLD): ?>
                    <span style="color:var(--amber-700);">⚠</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php foreach ($qs as $qq): ?>
                <tr>
                  <td style="padding-left:32px;" class="muted small" colspan="3">↳ <?= h($qq['text']) ?></td>
                  <td class="right muted small"><?= h(number_format((float)$qq['avg'], 2)) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php
        // Open antwoorden (blok 5) — per vraag gegroepeerd over alle deelnemers in alle DEMO-rondes
        $openRows = db_all(
            "SELECT os.question_id, q.text AS question_text, q.sort_order,
                    os.answer_text, sd.name AS deelnemer_name
               FROM demo_open_scores os
               JOIN traject_demo_questions q ON q.id = os.question_id
               JOIN scoring_deelnemers sd    ON sd.id = os.deelnemer_id
               JOIN scoring_rondes r         ON r.id = os.ronde_id
              WHERE r.traject_id = :t AND r.scope = 'DEMO' AND r.leverancier_id = :l
              ORDER BY q.sort_order, q.id, sd.name",
            [':t' => $trajectId, ':l' => $drillRow['leverancier_id']]
        );
        $openByQ = [];
        foreach ($openRows as $o) {
            $qid = (int)$o['question_id'];
            if (!isset($openByQ[$qid])) {
                $openByQ[$qid] = ['text' => (string)$o['question_text'], 'answers' => []];
            }
            $openByQ[$qid]['answers'][] = [
                'name' => (string)$o['deelnemer_name'],
                'text' => (string)$o['answer_text'],
            ];
        }
      ?>
      <?php
        // Filter lege antwoorden
        foreach ($openByQ as $qid => $grp) {
            $openByQ[$qid]['answers'] = array_values(array_filter(
                $grp['answers'],
                fn($a) => trim((string)$a['text']) !== ''
            ));
            if (!$openByQ[$qid]['answers']) unset($openByQ[$qid]);
        }
      ?>
      <?php if ($openByQ): ?>
        <style>
          .dmo-open-head {
            margin: 18px 0 0; padding: 8px 14px;
            background: #eff6ff; border: 1px solid #dbeafe;
            border-radius: 8px 8px 0 0;
            font-size: 13px; font-weight: 700; color: #1d4ed8;
            display: flex; align-items: center; gap: 8px;
          }
          .dmo-open-body {
            border: 1px solid #dbeafe; border-top: 0;
            border-radius: 0 0 8px 8px; padding: 12px 14px 14px;
            background: #fff;
          }
          .dmo-oq { margin-bottom: 16px; }
          .dmo-oq:last-child { margin-bottom: 0; }
          .dmo-oq-title {
            margin: 0 0 8px; font-size: 13.5px; font-weight: 600; color: #111827;
            display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap;
          }
          .dmo-oq-count {
            font-size: 11.5px; font-weight: 500; color: #6b7280;
            background: #f3f4f6; padding: 2px 8px; border-radius: 999px;
          }
          .dmo-oq-grid {
            display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px 10px;
          }
          .dmo-oq-grid.scroll {
            max-height: 360px; overflow-y: auto; padding-right: 4px;
          }
          .dmo-oq-item {
            background: #f9fafb; border-left: 3px solid #93c5fd;
            border-radius: 4px; padding: 8px 10px; font-size: 12.5px;
            line-height: 1.45;
          }
          .dmo-oq-name {
            display: block; font-size: 11px; font-weight: 700;
            color: #1d4ed8; text-transform: uppercase; letter-spacing: 0.02em;
            margin-bottom: 3px;
          }
          .dmo-oq-text { color: #1f2937; white-space: pre-wrap; word-break: break-word; }
          @media (max-width: 720px) {
            .dmo-oq-grid { grid-template-columns: 1fr; }
          }
        </style>
        <div class="dmo-open-head">
          Open antwoorden
          <span class="muted small" style="margin-left:auto;font-weight:400;">
            <?= count($openByQ) ?> <?= count($openByQ) === 1 ? 'vraag' : 'vragen' ?>
          </span>
        </div>
        <div class="dmo-open-body">
          <?php foreach ($openByQ as $qid => $grp):
            $n = count($grp['answers']);
            $scrollCls = $n > 8 ? ' scroll' : '';
          ?>
            <div class="dmo-oq">
              <p class="dmo-oq-title">
                <?= h($grp['text']) ?>
                <span class="dmo-oq-count"><?= $n ?> <?= $n === 1 ? 'antwoord' : 'antwoorden' ?></span>
              </p>
              <div class="dmo-oq-grid<?= $scrollCls ?>">
                <?php foreach ($grp['answers'] as $a): ?>
                  <div class="dmo-oq-item">
                    <span class="dmo-oq-name"><?= h($a['name']) ?></span>
                    <span class="dmo-oq-text"><?= h($a['text']) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      </details>
    </div>
  <?php endif; ?>
<?php };

require __DIR__ . '/../templates/layout.php';
