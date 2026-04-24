<?php
/**
 * Publieke scoringpagina — toegankelijk via persoonlijke token-link.
 * Geen login vereist; token bepaalt deelnemer + ronde + leverancier.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/scoring.php';
require_once __DIR__ . '/../includes/requirements.php';
require_once __DIR__ . '/../includes/leverancier_excel.php';
require_once __DIR__ . '/../includes/demo_catalog.php';
require_once __DIR__ . '/../includes/lev_answers.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$adminDeelnemerId = (int)($_GET['deelnemer_id'] ?? $_POST['deelnemer_id'] ?? 0);
$adminFlag = input_str('admin') === '1' || input_str('admin_mode') === '1';

$adminMode = false;
$d = null;

if ($adminDeelnemerId > 0 && $adminFlag && is_logged_in()) {
    $d = deelnemer_find_by_id($adminDeelnemerId);
    if ($d && can_edit_traject('trajecten.edit', (int)$d['traject_id'])) {
        $adminMode = true;
    } else {
        $d = null;
    }
} elseif ($token !== '') {
    $d = deelnemer_find_by_token($token);
    if ($adminFlag && $d && is_logged_in()) {
        $adminMode = can_edit_traject('trajecten.edit', (int)$d['traject_id']);
    }
}
$renderAdminBanner = function () use ($adminMode, $d) {
    if (!$adminMode) return;
    $u = $_SESSION['user'] ?? ['name' => 'admin'];
    ?>
    <div class="flash" style="background:#fff7ed;border:1px solid #fdba74;color:#9a3412;margin-bottom:14px;padding:10px 14px;border-radius:8px;">
      <strong>⚠ Admin-modus</strong> — je vult deze scoring in <strong>namens <?= h((string)$d['name']) ?></strong>
      (<?= h((string)$d['email']) ?>). Alle opgeslagen scores worden toegeschreven aan deze deelnemer
      en geaudit op jouw naam (<?= h((string)$u['name']) ?>).
    </div>
    <?php
};

$renderError = function (string $title, string $msg) {
    $pageTitle    = 'Scoring';
    $bodyRenderer = function () use ($title, $msg) { ?>
      <h2 style="margin-top:0;color:var(--red-700);"><?= h($title) ?></h2>
      <p class="muted"><?= h($msg) ?></p>
      <p class="muted small" style="margin-top:14px;">
        Neem contact op met de traject-beheerder als je denkt dat dit een vergissing is.
      </p>
    <?php };
    require __DIR__ . '/../templates/auth_layout.php';
    exit;
};

if (!$d) {
    $renderError('Ongeldige link', 'Deze scoring-link is onbekend of ingetrokken.');
}
if (!$adminMode && strtotime($d['token_expires']) < time()) {
    $renderError('Link verlopen', 'Deze scoring-link is verlopen. Vraag de beheerder om een nieuwe uitnodiging.');
}
if ($d['completed_at']) {
    $renderError('Al ingevuld', 'Je hebt de scoring voor deze ronde al afgerond. Bedankt!');
}
if ($d['ronde_status'] !== 'open') {
    $renderError('Ronde niet open',
        $d['ronde_status'] === 'concept'
          ? 'De scoring-ronde is nog niet geopend.'
          : 'De scoring-ronde is al gesloten.');
}

$rondeId       = (int)$d['ronde_id'];
$leverancierId = (int)$d['leverancier_id'];
$trajectId     = (int)$d['traject_id'];
$deelnemerId   = (int)$d['id'];
$scope         = (string)$d['scope'];

// Continue-URL voor saved-redirects: admin-mode gebruikt deelnemer_id, anders plaintext-token.
$continueUrl = $adminMode
    ? APP_BASE_URL . '/pages/score.php?deelnemer_id=' . $deelnemerId . '&admin=1'
    : APP_BASE_URL . '/pages/score.php?token=' . urlencode($token);

// Categorie-kleuren (hex) — voor cat-block headers en pills
$catColors = [
    'FUNC' => ['hex' => '#3b82f6', 'bg' => 'rgba(59,130,246,.07)', 'border' => 'rgba(59,130,246,.15)', 'pillBg' => 'rgba(59,130,246,.10)', 'pillFg' => '#2563eb'],
    'NFR'  => ['hex' => '#f59e0b', 'bg' => 'rgba(245,158,11,.07)', 'border' => 'rgba(245,158,11,.18)', 'pillBg' => 'rgba(245,158,11,.12)', 'pillFg' => '#b45309'],
    'VEND' => ['hex' => '#10b981', 'bg' => 'rgba(16,185,129,.07)', 'border' => 'rgba(16,185,129,.18)', 'pillBg' => 'rgba(16,185,129,.12)', 'pillFg' => '#059669'],
    'LIC'  => ['hex' => '#ef4444', 'bg' => 'rgba(239,68,68,.07)',  'border' => 'rgba(239,68,68,.18)',  'pillBg' => 'rgba(239,68,68,.10)',  'pillFg' => '#dc2626'],
    'SUP'  => ['hex' => '#8b5cf6', 'bg' => 'rgba(139,92,246,.07)', 'border' => 'rgba(139,92,246,.18)', 'pillBg' => 'rgba(139,92,246,.12)', 'pillFg' => '#7c3aed'],
    'DEMO' => ['hex' => '#ec4899', 'bg' => 'rgba(236,72,153,.07)', 'border' => 'rgba(236,72,153,.18)', 'pillBg' => 'rgba(236,72,153,.12)', 'pillFg' => '#be185d'],
];
$catFullNames = [
    'FUNC' => 'Functionele requirements',
    'NFR'  => 'Non-functionele requirements',
    'VEND' => 'Leverancier requirements',
    'LIC'  => 'Licentie requirements',
    'SUP'  => 'Support requirements',
    'DEMO' => 'Demo-beoordeling',
];
// Stappen
$steps = [
    1 => ['num' => '01', 'title' => 'Requirements opstellen', 'color' => '#0891b2'],
    2 => ['num' => '02', 'title' => 'Uitvraag leveranciers',  'color' => '#f59e0b'],
    3 => ['num' => '03', 'title' => 'Scoren uitvraag',        'color' => '#3b82f6'],
    4 => ['num' => '04', 'title' => 'Demo & beoordeling',     'color' => '#ec4899'],
    5 => ['num' => '05', 'title' => 'Rapportage & keuze',     'color' => '#10b981'],
];
$activeStep = $scope === 'DEMO' ? 4 : 3;
$activeColor = $steps[$activeStep]['color'];

$moscowLabel = fn($t) => ['eis' => 'MUST', 'wens' => 'SHOULD', 'ko' => 'KNOCK-OUT'][$t] ?? strtoupper($t);

/**
 * Gemeenschappelijke shell: dark hero + selectieproces + scoring-intro + pct.
 */
$renderShell = function (int $pctFilled, int $nFilled, int $nTotal) use ($d, $steps, $activeStep, $activeColor) { ?>
  <div class="sc-shell">
    <div class="sc-hero">
      <div class="sc-hero-inner">
        <div class="sc-hero-top">
          <div class="sc-brand">
            <div class="sc-logo">DKG</div>
            <div>
              <div class="sc-brand-name">DKG SelectieTool</div>
              <div class="sc-brand-sub">Softwareselectie platform</div>
            </div>
          </div>
          <div class="sc-ctx">
            <div class="sc-ctx-main"><?= h(strtoupper((string)$d['traject_name'])) ?></div>
            <div class="sc-ctx-sub"><?= h($d['scope']) ?> · <?= h($d['ronde_name']) ?></div>
          </div>
        </div>

        <div class="sc-flow-label">SELECTIEPROCES</div>
        <div class="sc-flow">
          <div class="sc-flow-line"></div>
          <?php foreach ($steps as $n => $st):
            $isActive = ($n === $activeStep);
          ?>
            <div class="sc-step<?= $isActive ? ' active' : '' ?>">
              <div class="sc-step-badge"
                   style="<?= $isActive ? 'background:' . h($st['color']) . ';color:#fff;box-shadow:0 0 0 4px ' . h($st['color']) . '33, 0 0 0 6px ' . h($st['color']) . '55;' : '' ?>">
                <?= h($st['num']) ?>
              </div>
              <div class="sc-step-title"><?= h($st['title']) ?></div>
              <?php if ($isActive): ?>
                <div class="sc-step-underline" style="background:<?= h($st['color']) ?>;"></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="sc-circle sc-circle-1"></div>
      <div class="sc-circle sc-circle-2"></div>
    </div>

    <div class="sc-intro">
      <div>
        <h1 class="sc-intro-title">Scoring voor <?= h($d['leverancier_name']) ?></h1>
        <p class="sc-intro-text">
          Hallo <strong><?= h($d['name']) ?></strong>, vul per requirement een score in van
          <strong>1 (zeer slecht)</strong> tot <strong>5 (zeer goed)</strong>. Laat leeg als je
          het punt niet kunt beoordelen. Tussentijds opslaan mag — je kunt later terugkomen
          via dezelfde link.
        </p>
      </div>
      <div class="sc-pct" style="--pct-color:<?= h($activeColor) ?>;">
        <div class="sc-pct-big"><?= (int)$pctFilled ?>%</div>
        <div class="sc-pct-label">ingevuld</div>
        <div class="sc-pct-sub"><?= (int)$nFilled ?> van <?= (int)$nTotal ?></div>
      </div>
    </div>
  </div>
<?php };

/**
 * Score-pill-bar (— 1 2 3 4 5) — gedeeld voor requirements en demo.
 */
$renderScorePills = function (string $name, int $current, bool $withZero = true) { ?>
  <div class="sc-pills" role="radiogroup">
    <span class="sc-pills-label">Score:</span>
    <?php
      $start = $withZero ? 0 : 1;
      for ($v = $start; $v <= 5; $v++):
    ?>
      <label class="sc-pill sc-pill-<?= $v ?><?= $current === $v ? ' checked' : '' ?>"
             title="<?= $v === 0 ? 'Leeg' : ('Score ' . $v) ?>">
        <input type="radio" name="<?= h($name) ?>" value="<?= $v ?>" <?= $current === $v ? 'checked' : '' ?>>
        <span><?= $v === 0 ? '—' : $v ?></span>
      </label>
    <?php endfor; ?>
  </div>
<?php };

// Gemeenschappelijke CSS + HTML-open
$emitHead = function (string $pageTitle) { ?>
<!doctype html>
<html lang="nl"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= h(APP_BASE_URL) ?>/public/assets/css/style.css?v=<?= h(APP_VERSION) ?>-<?= @filemtime(APP_ROOT . '/public/assets/css/style.css') ?>">
  <style>
    body { background: #f0f3f8; font-family: 'Nunito Sans', sans-serif; -webkit-font-smoothing: antialiased; margin:0; }
    .sc-wrap { max-width: 1100px; margin: 0 auto; padding: 0 20px 40px; }

    /* ── Dark hero ─────────────────────────────────────── */
    .sc-shell { margin: 20px auto 24px; }
    .sc-hero {
      position: relative; overflow: hidden;
      background: linear-gradient(135deg, #0d2d3a 0%, #134e4a 100%);
      border-radius: 16px 16px 0 0;
      padding: 24px 36px 32px;
      color:#fff;
    }
    .sc-hero-inner { position: relative; z-index: 2; }
    .sc-circle { position: absolute; border-radius: 50%; pointer-events: none; }
    .sc-circle-1 { width: 240px; height: 240px; top: -60px; right: -40px; background: rgba(14,165,233,.12); }
    .sc-circle-2 { width: 180px; height: 180px; bottom: -50px; right: 120px; background: rgba(16,185,129,.08); }

    .sc-hero-top {
      display: flex; justify-content: space-between; align-items: flex-start;
      gap: 20px; margin-bottom: 28px;
    }
    .sc-brand { display: flex; align-items: center; gap: 12px; }
    .sc-logo {
      width: 40px; height: 40px; border-radius: 9px;
      background: linear-gradient(135deg, #06b6d4, #22d3ee);
      color: #fff; font-weight: 800; font-size: 13px;
      display: flex; align-items: center; justify-content: center;
      letter-spacing: .02em;
    }
    .sc-brand-name { font-size: 15px; font-weight: 800; color: #fff; line-height: 1.2; }
    .sc-brand-sub  { font-size: 12px; color: rgba(255,255,255,.45); margin-top: 2px; }
    .sc-ctx { text-align: right; }
    .sc-ctx-main { font-size: 12px; font-weight: 700; color: rgba(255,255,255,.55);
                   letter-spacing: .08em; text-transform: uppercase; }
    .sc-ctx-sub  { font-size: 12px; font-weight: 700; color: rgba(255,255,255,.35);
                   letter-spacing: .08em; text-transform: uppercase; margin-top: 2px; }

    .sc-flow-label {
      font-size: 10.5px; font-weight: 700; color: rgba(255,255,255,.4);
      letter-spacing: .12em; margin-bottom: 14px;
    }
    .sc-flow {
      position: relative;
      display: grid; grid-template-columns: repeat(5, 1fr);
      gap: 8px;
      padding: 0 20px;
    }
    .sc-flow-line {
      position: absolute; top: 14px; left: 10%; right: 10%; height: 1px;
      background: rgba(255,255,255,.08);
      z-index: 0;
    }
    .sc-step {
      position: relative; display: flex; flex-direction: column; align-items: center;
      gap: 8px; z-index: 1;
    }
    .sc-step-badge {
      width: 28px; height: 28px; border-radius: 50%;
      background: #1a1f2e; border: 1px solid rgba(255,255,255,.12);
      display: flex; align-items: center; justify-content: center;
      font-size: 10.5px; font-weight: 800; color: rgba(255,255,255,.45);
      letter-spacing: .04em;
      transition: all .2s;
    }
    .sc-step-title {
      font-size: 12px; font-weight: 600; color: rgba(255,255,255,.35);
      text-align: center; line-height: 1.3;
    }
    .sc-step.active .sc-step-title { color: #fff; font-weight: 700; }
    .sc-step-underline {
      width: 28px; height: 2px; border-radius: 2px; margin-top: 2px;
    }

    /* ── Scoring-intro (wit blok direct onder hero) ──────── */
    .sc-intro {
      background: #fff;
      border-radius: 0 0 16px 16px;
      padding: 28px 36px 32px;
      display: flex; justify-content: space-between; gap: 24px; align-items: center;
      box-shadow: 0 4px 16px rgba(0,0,0,.04);
    }
    .sc-intro-title { margin: 0 0 6px; font-size: 22px; font-weight: 800; color: #0d1117; }
    .sc-intro-text  { margin: 0; font-size: 14px; line-height: 1.6; color: #6b7280; max-width: 640px; }
    .sc-pct {
      flex-shrink: 0; background: rgba(14,165,233,.06);
      border-radius: 14px; padding: 16px 28px; text-align: center;
      min-width: 140px;
    }
    .sc-pct-big {
      font-size: 36px; font-weight: 800; color: var(--pct-color, #0891b2);
      letter-spacing: -1.5px; line-height: 1;
    }
    .sc-pct-label { font-size: 12px; font-weight: 600; color: #6b7280; margin-top: 2px; }
    .sc-pct-sub   { font-size: 11.5px; color: #9ca3af; margin-top: 10px; font-weight: 500; }

    /* ── Categorie-blok (zoals requirements-pagina) ──────── */
    .sc-cat {
      background: #fff; border-radius: 14px; overflow: hidden;
      box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 16px rgba(0,0,0,.04);
      margin-bottom: 18px;
    }
    .sc-cat-head {
      display: flex; align-items: center; gap: 12px;
      padding: 14px 20px;
    }
    .sc-cpill {
      display: inline-flex; padding: 3px 9px; border-radius: 6px;
      font-size: 11px; font-weight: 700; letter-spacing: .03em;
    }
    .sc-cat-title { margin: 0; font-size: 15px; font-weight: 700; color: #111827; flex: 1; }
    .sc-cat-count { font-size: 12.5px; font-weight: 600; }

    .sc-sub-label {
      padding: 10px 20px 6px;
      border-top: 1px solid #f3f4f6;
      font-size: 12px; font-weight: 600; color: #9ca3af;
    }

    .sc-req {
      padding: 16px 20px 14px;
      border-top: 1px solid #f3f4f6;
    }
    .sc-req-head {
      display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
    }
    .sc-req-main { flex: 1; min-width: 0; }
    .sc-req-title { font-size: 14.5px; font-weight: 700; color: #111827; line-height: 1.35; margin: 0 0 4px; }
    .sc-req-desc  { font-size: 13px; color: #6b7280; line-height: 1.6; margin: 0; }
    .sc-req-meta  { display: flex; align-items: center; gap: 14px; flex-shrink: 0; padding-top: 2px; }
    .sc-moscow    { font-size: 11px; font-weight: 700; letter-spacing: .04em; color: #d1d5db; }
    .sc-moscow.ko { color: #dc2626; font-weight: 800; }
    .sc-code      { font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                    font-size: 10.5px; color: #d1d5db; font-weight: 600; }

    /* Score-pills — zelfde stijl als screenshot */
    .sc-pills {
      display: flex; align-items: center; gap: 8px;
      margin: 12px 0 0;
      flex-wrap: wrap;
    }
    .sc-pills-label { font-size: 13px; color: #6b7280; font-weight: 600; margin-right: 4px; }
    .sc-pill {
      position: relative;
      display: inline-flex; align-items: center; justify-content: center;
      width: 44px; height: 40px; border-radius: 10px;
      border: 1.5px solid var(--border, #e5e8ef);
      background: #fff; color: #111827;
      font-weight: 700; font-size: 14px; cursor: pointer;
      transition: all .12s;
      user-select: none;
    }
    .sc-pill:hover { border-color: #9ca3af; transform: translateY(-1px); }
    .sc-pill input { position: absolute; opacity: 0; pointer-events: none; width:0; height:0; }
    .sc-pill.checked {
      background: #0891b2; border-color: #0891b2; color: #fff;
      box-shadow: 0 4px 14px rgba(14,165,233,.3);
    }
    .sc-pill-0.checked {
      background: #e5e8ef; border-color: #d1d5db; color: #6b7280;
      box-shadow: none;
    }

    .sc-note {
      margin-top: 12px; width: 100%;
      border: 1px solid var(--border, #e5e8ef);
      border-radius: 10px; padding: 12px 14px;
      font-family: inherit; font-size: 13px; color: #111827;
      background: #f8f9fa; resize: vertical; min-height: 48px;
    }
    .sc-note:focus { outline: none; border-color: #0891b2; background: #fff; }

    /* Leverancier-antwoord blok boven de scoring */
    .sc-lev-ans {
      margin-top: 10px; padding: 10px 12px;
      border-left: 3px solid #22d3ee; background: #f8f9fa;
      border-radius: 6px; font-size: 13px; line-height: 1.5;
    }
    .sc-lev-ans-head {
      display: flex; align-items: center; gap: 8px;
      font-size: 10.5px; font-weight: 700; letter-spacing: .08em;
      color: #6b7280; text-transform: uppercase; margin-bottom: 4px;
    }
    .sc-auto-badge {
      display: inline-flex; padding: 2px 8px; border-radius: 20px;
      background: #0891b2; color: #fff; font-size: 10.5px; font-weight: 700; letter-spacing: .04em;
    }

    /* Sticky actions */
    .sc-actions {
      position: sticky; bottom: 0; z-index: 20;
      background: rgba(240,243,248,.95); backdrop-filter: blur(8px);
      border-top: 1px solid #e5e8ef;
      padding: 14px 0; margin-top: 20px;
      display: flex; gap: 10px; justify-content: flex-end;
    }

    /* Auto-section (collapse) */
    .sc-auto-details summary {
      cursor: pointer; padding: 14px 20px; font-weight: 700;
      color: #374151; font-size: 14px;
      list-style: none;
    }
    .sc-auto-details summary::-webkit-details-marker { display: none; }
    .sc-auto-details[open] summary { border-bottom: 1px solid #f3f4f6; }

    @media (max-width: 768px) {
      .sc-hero { padding: 20px 20px 24px; border-radius: 12px 12px 0 0; }
      .sc-hero-top { flex-direction: column; gap: 12px; }
      .sc-ctx { text-align: left; }
      .sc-flow { padding: 0; }
      .sc-step-title { font-size: 10.5px; }
      .sc-intro { flex-direction: column; align-items: flex-start; padding: 20px; border-radius: 0 0 12px 12px; }
      .sc-pct { align-self: stretch; }
      .sc-req-head { flex-direction: column; gap: 8px; }
      .sc-pill { width: 40px; height: 36px; font-size: 13px; }
    }
  </style>
</head><body>
<?php };

// ─────────────────────────────── DEMO SCORING ────────────────────────────────
if ($scope === 'DEMO') {
    $catalog  = demo_catalog_grouped($trajectId, true);
    $activeQs = demo_catalog_active($trajectId);
    if (!$activeQs) {
        $renderError('Geen demo-vragen',
            'De demo-vragenlijst is voor dit traject nog niet geconfigureerd. Neem contact op met de beheerder.');
    }
    // Splits in score-vragen (blok 1–4) en open vragen (blok 5)
    $scoreQIds = []; $openQIds = [];
    foreach ($activeQs as $q) {
        if (demo_block_is_open((int)$q['block'])) $openQIds[]  = (int)$q['id'];
        else                                      $scoreQIds[] = (int)$q['id'];
    }

    $errors = [];
    $postScores = []; $postOpen = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_require();
        $postScores = is_array($_POST['score']  ?? null) ? $_POST['score']  : [];
        $postOpen   = is_array($_POST['open_q'] ?? null) ? $_POST['open_q'] : [];
        $final      = input_str('final') === '1';

        // Altijd opslaan wat is ingevuld (progress niet verliezen bij final-validatie).
        db_transaction(function () use ($postScores, $postOpen, $rondeId, $deelnemerId, $scoreQIds, $openQIds) {
            foreach ($postScores as $qid => $val) {
                $qid = (int)$qid;
                if (!in_array($qid, $scoreQIds, true)) continue;
                $v = (int)$val;
                if ($v < 1 || $v > 5) continue;
                demo_score_upsert($rondeId, $qid, $deelnemerId, $v);
            }
            foreach ($postOpen as $qid => $text) {
                $qid = (int)$qid;
                if (!in_array($qid, $openQIds, true)) continue;
                demo_open_score_upsert($rondeId, $qid, $deelnemerId, (string)$text);
            }
        });

        $incomplete = false;
        if ($final) {
            // Merge net-opgeslagen POST met eerder opgeslagen scores voor de completeness-check.
            $savedNow = demo_scores_for_deelnemer($deelnemerId);
            foreach ($scoreQIds as $qid) {
                $v = (int)($postScores[$qid] ?? ($savedNow[$qid] ?? 0));
                if ($v < 1 || $v > 5) { $incomplete = true; break; }
            }
        }

        if ($adminMode) {
            audit_log(
                'score.admin_submit', 'scoring_deelnemer', $deelnemerId,
                sprintf('scope=DEMO traject=%d lev=%d ronde=%d final=%d deelnemer=%s',
                    $trajectId, $leverancierId, $rondeId, $final && !$incomplete ? 1 : 0, (string)$d['name'])
            );
        }
        if ($final && !$incomplete) {
            deelnemer_mark_completed($deelnemerId);
            $renderError('Bedankt!', 'Je demo-scoring is ontvangen en definitief opgeslagen.');
        }
        if ($final && $incomplete) {
            flash_set('error', 'Niet alle verplichte score-vragen (blok 1–4) zijn ingevuld. Je voortgang is opgeslagen — vul de ontbrekende vragen in en probeer opnieuw.');
        }
        header('Location: ' . $continueUrl . '&saved=1');
        exit;
    }

    $saved    = !empty($_GET['saved']);
    $existing = demo_scores_for_deelnemer($deelnemerId);
    $openEx   = demo_open_scores_for_deelnemer($rondeId, $deelnemerId);
    $postedScores = $_SERVER['REQUEST_METHOD'] === 'POST' ? $postScores : [];
    $postedOpen   = $_SERVER['REQUEST_METHOD'] === 'POST' ? $postOpen   : [];

    // Percentage ingevuld — alleen score-vragen (open vragen zijn optioneel)
    $nTotal  = count($scoreQIds);
    $nFilled = 0;
    foreach ($scoreQIds as $qid) {
        $v = (int)($postedScores[$qid] ?? ($existing[$qid] ?? 0));
        if ($v >= 1 && $v <= 5) $nFilled++;
    }
    $pct = $nTotal > 0 ? (int)round(100 * $nFilled / $nTotal) : 0;

    $emitHead('Demo-scoring — ' . $d['leverancier_name']);
?>
<div class="sc-wrap">
  <?php $renderShell($pct, $nFilled, $nTotal); ?>

  <?php $renderAdminBanner(); ?>
  <?php flash_pull(); ?>
  <?php if ($saved && !$errors): ?>
    <div class="flash flash-success" style="margin-bottom:14px;">Je voortgang is opgeslagen.</div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="flash flash-error" style="margin-bottom:14px;">
      <ul style="margin:0;padding-left:20px;">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= h($token) ?>">
    <?php if ($adminMode): ?><input type="hidden" name="admin" value="1"><?php endif; ?>

    <?php $c = $catColors['DEMO']; foreach (DEMO_BLOCKS as $block => $meta):
      $qs = $catalog[$block]['questions'] ?? [];
      if (!$qs) continue;
      $isOpen = demo_block_is_open($block);
    ?>
      <div class="sc-cat" style="border:1px solid <?= h($c['border']) ?>;">
        <div class="sc-cat-head" style="background:<?= h($c['bg']) ?>;border-bottom:1px solid <?= h($c['border']) ?>;">
          <span class="sc-cpill" style="background:<?= h($c['pillBg']) ?>;color:<?= h($c['pillFg']) ?>;">BLOK <?= (int)$block ?></span>
          <h2 class="sc-cat-title"><?= h($meta['title']) ?></h2>
          <span class="sc-cat-count" style="color:<?= h($c['pillFg']) ?>;">
            <?= count($qs) ?> vragen<?= $isOpen ? ' · optioneel' : '' ?>
          </span>
        </div>
        <div class="sc-sub-label"><?= h($meta['subtitle']) ?></div>
        <?php foreach ($qs as $q):
          $qid = (int)$q['id'];
        ?>
          <div class="sc-req">
            <div class="sc-req-head">
              <div class="sc-req-main">
                <p class="sc-req-title"><?= h($q['text']) ?></p>
              </div>
              <div class="sc-req-meta">
                <code class="sc-code">Q-<?= sprintf('%03d', $qid) ?></code>
              </div>
            </div>
            <?php if ($isOpen):
              $txt = (string)($postedOpen[$qid] ?? ($openEx[$qid] ?? ''));
            ?>
              <textarea class="sc-note" name="open_q[<?= $qid ?>]" rows="3" maxlength="2000"
                        placeholder="Optioneel — laat leeg als je geen opmerking hebt"><?= h($txt) ?></textarea>
            <?php else:
              $cur = (int)($postedScores[$qid] ?? ($existing[$qid] ?? 0));
              $renderScorePills("score[$qid]", $cur, true);
            endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <div class="sc-actions">
      <button type="submit" name="final" value="0" class="btn ghost">Tussentijds opslaan</button>
      <button type="submit" name="final" value="1" class="btn"
              onclick="return confirm('Definitief versturen? Daarna kun je je scoring niet meer aanpassen.');">
        <?= icon('check', 14) ?> Definitief versturen
      </button>
    </div>
  </form>
</div>
<script>
  document.addEventListener('change', function (e) {
    var inp = e.target;
    if (!inp.matches('.sc-pill input[type=radio]')) return;
    document.querySelectorAll('input[name="' + inp.name + '"]').forEach(function (r) {
      r.closest('.sc-pill').classList.toggle('checked', r.checked);
    });
  });
  // Disable "Definitief versturen" totdat alle verplichte score-vragen (1–5) zijn ingevuld.
  // Open vragen (textarea) en auto-gescoorde requirements (in <details class="sc-auto-details">) zijn optioneel.
  (function () {
    var form = document.querySelector('form[method="post"]');
    if (!form) return;
    var finalBtn = form.querySelector('button[name="final"][value="1"]');
    if (!finalBtn) return;
    var requiredNames = new Set();
    form.querySelectorAll('.sc-pill input[type=radio]').forEach(function (r) {
      if (r.closest('.sc-auto-details')) return;
      requiredNames.add(r.name);
    });
    var update = function () {
      var filled = 0;
      requiredNames.forEach(function (name) {
        var sel = form.querySelector('input[name="' + name + '"]:checked');
        if (sel && +sel.value >= 1 && +sel.value <= 5) filled++;
      });
      var missing = requiredNames.size - filled;
      finalBtn.disabled = missing > 0 || requiredNames.size === 0;
      finalBtn.title = missing > 0
        ? ('Nog ' + missing + ' verplichte score' + (missing === 1 ? '' : 's') + ' in te vullen — gebruik "Tussentijds opslaan" om later verder te gaan.')
        : '';
      finalBtn.style.opacity = missing > 0 ? '0.55' : '';
      finalBtn.style.cursor  = missing > 0 ? 'not-allowed' : '';
    };
    form.addEventListener('change', update);
    update();
  })();
</script>
</body></html>
<?php
    exit;
}

// ─────────────────────────── REQUIREMENTS SCORING ─────────────────────────────

$reqs = db_all(
    'SELECT r.id, r.code, r.title, r.description, r.type,
            s.id AS sub_id, s.name AS sub_name, s.sort_order AS sub_order,
            c.id AS cat_id, c.name AS cat_name, c.code AS cat_code, c.sort_order AS cat_order,
            a.label AS app_label, a.description AS app_description
       FROM requirements r
       JOIN subcategorieen s ON s.id = r.subcategorie_id
       JOIN categorieen    c ON c.id = s.categorie_id
       LEFT JOIN applicatiesoorten a ON a.id = s.applicatiesoort_id
      WHERE r.traject_id = :t AND c.code = :code
      ORDER BY s.sort_order, r.sort_order, r.id',
    [':t' => $trajectId, ':code' => $scope]
);

$levAnswers = leverancier_answers_for($leverancierId);

// Partitioneer requirements — auto-gescoord apart in collapse
$reqsMain = []; // handmatig + KO (beide vragen aandacht)
$reqsAuto = []; // auto_max / auto_min
$reqsSkip = []; // nvt
foreach ($reqs as $r) {
    $rid = (int)$r['id'];
    $la  = $levAnswers[$rid] ?? null;
    $ans = [
        'answer_choice' => $la['choice'] ?? '',
        'answer_text'   => $la['text']   ?? '',
        'evidence_url'  => $la['url']    ?? '',
    ];
    $cls = lev_classify_answer($ans, ['type' => $r['type']]);
    $r['_class']  = $cls['class'];
    switch ($cls['class']) {
        case 'auto_max':
        case 'auto_min':
            $reqsAuto[] = $r; break;
        case 'skip':
            $reqsSkip[] = $r; break;
        default:
            $reqsMain[] = $r; // manual, ko_manual, ko_fail_auto
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $scores = $_POST['score']  ?? [];
    $notes  = $_POST['notes']  ?? [];
    $final  = input_str('final') === '1';

    db_transaction(function () use ($scores, $notes, $rondeId, $leverancierId, $deelnemerId, $reqs) {
        $allowedReqIds = array_map('intval', array_column($reqs, 'id'));
        foreach ($scores as $reqId => $val) {
            $rid = (int)$reqId;
            if (!in_array($rid, $allowedReqIds, true)) continue;
            $score = (int)$val;
            $note  = isset($notes[$reqId]) ? trim((string)$notes[$reqId]) : null;
            score_upsert($rondeId, $leverancierId, $rid, $deelnemerId, $score, $note);
        }
    });

    // Completeness-check bij definitief versturen: alleen handmatige requirements ($reqsMain).
    // Auto-gescoorde ($reqsAuto) hoeven niet opnieuw — die zijn al gescoord via leverancier-antwoorden.
    $incomplete = false;
    if ($final) {
        $savedNow = scores_for_deelnemer($deelnemerId);
        foreach ($reqsMain as $r) {
            $rid = (int)$r['id'];
            $v   = (int)($scores[$rid] ?? ($savedNow[$rid]['score'] ?? 0));
            if ($v < 1 || $v > 5) { $incomplete = true; break; }
        }
    }

    if ($adminMode) {
        audit_log(
            'score.admin_submit', 'scoring_deelnemer', $deelnemerId,
            sprintf('scope=%s traject=%d lev=%d ronde=%d final=%d deelnemer=%s',
                $scope, $trajectId, $leverancierId, $rondeId, $final && !$incomplete ? 1 : 0, (string)$d['name'])
        );
    }
    if ($final && !$incomplete) {
        deelnemer_mark_completed($deelnemerId);
        $renderError('Bedankt!', 'Je scoring is ontvangen en definitief opgeslagen.');
    }
    if ($final && $incomplete) {
        flash_set('error', 'Niet alle verplichte requirements zijn gescoord. Je voortgang is opgeslagen — vul de ontbrekende scores in en probeer opnieuw.');
    }
    header('Location: ' . $continueUrl . '&saved=1');
    exit;
}

$saved      = !empty($_GET['saved']);
$existing   = scores_for_deelnemer($deelnemerId);

$autoRows = db_all(
    "SELECT requirement_id, score, notes FROM scores
      WHERE ronde_id = :r AND deelnemer_id IS NULL AND source = 'auto'",
    [':r' => $rondeId]
);
$autoByReq = [];
foreach ($autoRows as $ar) {
    $autoByReq[(int)$ar['requirement_id']] = [
        'score' => (int)$ar['score'],
        'notes' => (string)($ar['notes'] ?? ''),
    ];
}

// % ingevuld (handmatig beoordeeld)
$nTotal  = count($reqsMain);
$nFilled = 0;
foreach ($reqsMain as $r) {
    $rid = (int)$r['id'];
    $sc  = (int)($existing[$rid]['score'] ?? 0);
    if ($sc >= 1 && $sc <= 5) $nFilled++;
}
$pct = $nTotal > 0 ? (int)round(100 * $nFilled / $nTotal) : 0;

// Groepeer main-lijst per sub
$mainBySub = [];
foreach ($reqsMain as $r) {
    $sid = (int)$r['sub_id'];
    $mainBySub[$sid]['name']   = $r['sub_name'];
    $mainBySub[$sid]['reqs'][] = $r;
}
$autoBySub = [];
foreach ($reqsAuto as $r) {
    $sid = (int)$r['sub_id'];
    $autoBySub[$sid]['name']   = $r['sub_name'];
    $autoBySub[$sid]['reqs'][] = $r;
}

$renderReqRow = function (array $r) use ($existing, $levAnswers, $autoByReq, $renderScorePills, $moscowLabel) {
    $rid = (int)$r['id'];
    $ex  = $existing[$rid] ?? ['score' => 0, 'notes' => ''];
    $auto = $autoByReq[$rid] ?? null;
    $la   = $levAnswers[$rid] ?? null;
    $current = (int)$ex['score'];
    if ($current === 0 && $auto) $current = (int)$auto['score'];
    $mLabel = $moscowLabel($r['type']);
    $isKO   = $r['type'] === 'ko';
?>
  <div class="sc-req">
    <div class="sc-req-head">
      <div class="sc-req-main">
        <p class="sc-req-title"><?= h($r['title']) ?></p>
        <?php if ($r['description']): ?>
          <p class="sc-req-desc"><?= h($r['description']) ?></p>
        <?php endif; ?>
      </div>
      <div class="sc-req-meta">
        <?php if ($auto): ?>
          <span class="sc-auto-badge" title="<?= h((string)($auto['notes'] ?? '')) ?>">AUTO <?= (int)$auto['score'] ?></span>
        <?php endif; ?>
        <span class="sc-moscow<?= $isKO ? ' ko' : '' ?>"><?= h($mLabel) ?></span>
        <code class="sc-code"><?= h($r['code']) ?></code>
      </div>
    </div>
    <?php if ($la && ($la['choice'] !== '' || $la['text'] !== '' || $la['url'] !== '')): ?>
      <div class="sc-lev-ans">
        <div class="sc-lev-ans-head">
          <span>Antwoord leverancier</span>
          <?php if ($la['choice'] !== ''): ?><?= leverancier_answer_badge($la['choice']) ?><?php endif; ?>
        </div>
        <?php if ($la['text'] !== ''): ?>
          <div style="white-space:pre-line;"><?= h($la['text']) ?></div>
        <?php endif; ?>
        <?php $laUrl = safe_url($la['url'] ?? ''); if ($laUrl !== ''): ?>
          <div style="margin-top:4px;"><a href="<?= h($laUrl) ?>" target="_blank" rel="noopener noreferrer">Bewijsmateriaal ↗</a></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php $renderScorePills("score[$rid]", $current, true); ?>
    <textarea class="sc-note" rows="2" maxlength="1000"
              name="notes[<?= $rid ?>]"
              placeholder="Optionele toelichting…"><?= h($ex['notes']) ?></textarea>
  </div>
<?php };

$c = $catColors[$scope] ?? $catColors['FUNC'];
$catTitle = $catFullNames[$scope] ?? ($reqs[0]['cat_name'] ?? $scope);

$emitHead('Scoring — ' . $d['leverancier_name']);
?>
<div class="sc-wrap">
  <?php $renderShell($pct, $nFilled, $nTotal); ?>

  <?php $renderAdminBanner(); ?>
  <?php flash_pull(); ?>
  <?php if ($saved): ?>
    <div class="flash flash-success" style="margin-bottom:14px;">Je voortgang is opgeslagen.</div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= h($token) ?>">
    <?php if ($adminMode): ?><input type="hidden" name="admin" value="1"><?php endif; ?>

    <?php if ($reqsMain): ?>
      <div class="sc-cat" style="border:1px solid <?= h($c['border']) ?>;">
        <div class="sc-cat-head" style="background:<?= h($c['bg']) ?>;border-bottom:1px solid <?= h($c['border']) ?>;">
          <span class="sc-cpill" style="background:<?= h($c['pillBg']) ?>;color:<?= h($c['pillFg']) ?>;"><?= h($scope) ?></span>
          <h2 class="sc-cat-title"><?= h($catTitle) ?></h2>
          <span class="sc-cat-count" style="color:<?= h($c['pillFg']) ?>;"><?= (int)$nTotal ?> requirement<?= $nTotal === 1 ? '' : 's' ?></span>
        </div>
        <?php foreach ($mainBySub as $sid => $sub): ?>
          <div class="sc-sub-label"><?= h($sub['name']) ?></div>
          <?php foreach ($sub['reqs'] as $r) $renderReqRow($r); ?>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="sc-cat" style="border:1px solid #e5e8ef;">
        <div class="sc-cat-head" style="background:#f8f9fa;">
          <h2 class="sc-cat-title">Geen regels om handmatig te scoren</h2>
        </div>
        <div style="padding:16px 20px;color:#6b7280;font-size:13px;">Alle regels zijn al automatisch gescoord op basis van leverancier-antwoorden.</div>
      </div>
    <?php endif; ?>

    <?php if ($reqsAuto): ?>
      <details class="sc-cat sc-auto-details" style="border:1px solid #e5e8ef;">
        <summary>
          <span style="display:inline-flex;align-items:center;gap:10px;">
            <span class="sc-cpill" style="background:rgba(14,165,233,.10);color:#0891b2;">AUTO</span>
            Automatisch gescoord (<?= count($reqsAuto) ?>) — klik om te bekijken / overrulen
          </span>
        </summary>
        <?php foreach ($autoBySub as $sid => $sub): ?>
          <div class="sc-sub-label"><?= h($sub['name']) ?></div>
          <?php foreach ($sub['reqs'] as $r) $renderReqRow($r); ?>
        <?php endforeach; ?>
      </details>
    <?php endif; ?>

    <div class="sc-actions">
      <button type="submit" name="final" value="0" class="btn ghost">Tussentijds opslaan</button>
      <button type="submit" name="final" value="1" class="btn"
              onclick="return confirm('Definitief versturen? Daarna kun je je scoring niet meer aanpassen.');">
        <?= icon('check', 14) ?> Definitief versturen
      </button>
    </div>
  </form>
</div>
<script>
  document.addEventListener('change', function (e) {
    var inp = e.target;
    if (!inp.matches('.sc-pill input[type=radio]')) return;
    document.querySelectorAll('input[name="' + inp.name + '"]').forEach(function (r) {
      r.closest('.sc-pill').classList.toggle('checked', r.checked);
    });
  });
  // Disable "Definitief versturen" totdat alle verplichte requirements (handmatig) zijn gescoord.
  // Auto-gescoorde requirements (binnen <details class="sc-auto-details">) tellen niet als verplicht.
  (function () {
    var form = document.querySelector('form[method="post"]');
    if (!form) return;
    var finalBtn = form.querySelector('button[name="final"][value="1"]');
    if (!finalBtn) return;
    var requiredNames = new Set();
    form.querySelectorAll('.sc-pill input[type=radio]').forEach(function (r) {
      if (r.closest('.sc-auto-details')) return;
      requiredNames.add(r.name);
    });
    var update = function () {
      var filled = 0;
      requiredNames.forEach(function (name) {
        var sel = form.querySelector('input[name="' + name + '"]:checked');
        if (sel && +sel.value >= 1 && +sel.value <= 5) filled++;
      });
      var missing = requiredNames.size - filled;
      finalBtn.disabled = missing > 0 || requiredNames.size === 0;
      finalBtn.title = missing > 0
        ? ('Nog ' + missing + ' verplichte score' + (missing === 1 ? '' : 's') + ' in te vullen — gebruik "Tussentijds opslaan" om later verder te gaan.')
        : '';
      finalBtn.style.opacity = missing > 0 ? '0.55' : '';
      finalBtn.style.cursor  = missing > 0 ? 'not-allowed' : '';
    };
    form.addEventListener('change', update);
    update();
  })();
</script>
</body>
</html>
