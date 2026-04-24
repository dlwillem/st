<?php
/**
 * Home — welkom, dark hero met live stats en 5-stappen procesflow.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/trajecten.php';
require_login();

$user = current_user();

// ─── Traject-scoping ─────────────────────────────────────────────────────────
$allowedIds = user_allowed_traject_ids();
if ($allowedIds === null) {
    $scopeWhere     = '';
    $scopeTrajWhere = '';
} elseif (empty($allowedIds)) {
    $scopeWhere     = ' AND 0';
    $scopeTrajWhere = 'WHERE 0';
} else {
    $ids = implode(',', array_map('intval', $allowedIds));
    $scopeWhere     = ' AND traject_id IN (' . $ids . ')';
    $scopeTrajWhere = 'WHERE id IN (' . $ids . ')';
}

// ─── Live statistieken voor hero ─────────────────────────────────────────────
$nTrajAll = (int)db_value('SELECT COUNT(*) FROM trajecten ' . $scopeTrajWhere);
$nReqAll  = (int)db_value('SELECT COUNT(*) FROM requirements WHERE 1=1' . $scopeWhere);
$nLevAll  = (int)db_value('SELECT COUNT(*) FROM leveranciers WHERE 1=1' . $scopeWhere);

// ─── Quote van de dag ────────────────────────────────────────────────────────
// Wisselt automatisch op basis van dag-van-het-jaar (date('z')). Als de
// `quotes`-tabel nog niet bestaat (migratie niet gedraaid), tonen we niets.
$quoteOfDay = null;
try {
    $nQuotes = (int)db_value("SELECT COUNT(*) FROM quotes");
    if ($nQuotes > 0) {
        $offset = (int)date('z') % $nQuotes;
        $quoteOfDay = db_one(
            "SELECT tekst, auteur FROM quotes ORDER BY id LIMIT 1 OFFSET $offset"
        );
    }
} catch (Throwable $e) {
    $quoteOfDay = null;
}

$pageTitle  = 'Home';
$currentNav = 'home';

$hour = (int)date('H');
if ($hour < 6)       $greet = 'Goedenacht';
elseif ($hour < 12)  $greet = 'Goedemorgen';
elseif ($hour < 18)  $greet = 'Goedemiddag';
else                 $greet = 'Goedenavond';

$bodyRenderer = function () use (
    $user, $greet, $nTrajAll, $nReqAll, $nLevAll, $quoteOfDay
) {
    $firstName = explode(' ', (string)($user['name'] ?? ''))[0] ?: 'daar';

    $iconDoc = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>';
    $iconBuild = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/></svg>';
    $iconStar = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
    $iconDemo = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>';
    $iconChart = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>';

    $steps = [
        ['num' => '01', 'color' => '#0891b2', 'icon' => $iconDoc,   'title' => 'Requirements opstellen',
         'text' => 'Definieer functionele en non-functionele eisen per categorie en stel MoSCoW-prioriteiten in.'],
        ['num' => '02', 'color' => '#f59e0b', 'icon' => $iconBuild, 'title' => 'Uitvraag leveranciers',
         'text' => 'Download een gepersonaliseerde Excel per leverancier. Leveranciers vullen in zonder eigen login. Upload het ingevulde bestand terug.'],
        ['num' => '03', 'color' => '#3b82f6', 'icon' => $iconStar,  'title' => 'Scoren uitvraag',
         'text' => 'De tool scoort automatisch waar het kan. Beoordelaars beoordelen de antwoorden van leveranciers daar waar nog nodig.'],
        ['num' => '04', 'color' => '#ec4899', 'icon' => $iconDemo,  'title' => 'Demo & beoordeling',
         'text' => 'Leveranciers presenteren hun oplossing live. Beoordelaars scoren de demo onafhankelijk van elkaar voor een objectief eindoordeel.'],
        ['num' => '05', 'color' => '#10b981', 'icon' => $iconChart, 'title' => 'Rapportage & keuze',
         'text' => 'Gewogen eindrangschikking over alle leveranciers. Transparante onderbouwing per categorie voor de besluitvorming.'],
    ];
?>
  <!-- Sectie 1: greeting -->
  <div class="hp-greet">
    <h1 class="hp-greet-title"><?= h($greet) ?>, <?= h($firstName) ?> 👋</h1>
    <div class="hp-greet-sub">Overzicht van jouw selectietrajecten bij DKG</div>
  </div>

  <!-- Sectie 2: dark hero -->
  <div class="hp-hero">
    <div class="hp-hero-deco hp-hero-deco-tr"></div>
    <div class="hp-hero-deco hp-hero-deco-br"></div>

    <div class="hp-hero-left">
      <div class="hp-hero-pill">DKG SELECTIETOOL v2.0</div>
      <h2 class="hp-hero-title">
        Van requirements tot eindrapportage —<br>
        één plek voor elk softwareselectie-traject.
      </h2>
      <p class="hp-hero-lede">
        De SelectieTool bundelt de volledige flow: stel requirements samen,
        vraag leveranciers uit via een gepersonaliseerd Excel, laat de tool
        automatisch scoren waar het kan, en beoordeel handmatig waar het moet —
        tot een gewogen eindrangschikking.
      </p>
    </div>

    <div class="hp-hero-stats">
      <div class="hp-stat">
        <div class="hp-stat-num" style="color:#22d3ee;"><?= (int)$nTrajAll ?></div>
        <div class="hp-stat-lbl">TRAJECTEN</div>
      </div>
      <div class="hp-stat">
        <div class="hp-stat-num" style="color:#60a5fa;"><?= (int)$nReqAll ?></div>
        <div class="hp-stat-lbl">REQUIREMENTS</div>
      </div>
      <div class="hp-stat">
        <div class="hp-stat-num" style="color:#34d399;"><?= (int)$nLevAll ?></div>
        <div class="hp-stat-lbl">LEVERANCIERS</div>
      </div>
    </div>
  </div>

  <?php if ($quoteOfDay): ?>
    <!-- Quote van de dag -->
    <div class="hp-quote">
      <div class="hp-quote-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M7.5 6C4.5 6 3 8.5 3 11.5c0 2.5 1.5 4.5 4 4.5.5 0 1-.1 1.5-.2-.5 1.2-1.6 2-3 2.2v2c3.5-.2 6-2.7 6-7V11c0-3-1.5-5-4-5zm9 0C13.5 6 12 8.5 12 11.5c0 2.5 1.5 4.5 4 4.5.5 0 1-.1 1.5-.2-.5 1.2-1.6 2-3 2.2v2c3.5-.2 6-2.7 6-7V11c0-3-1.5-5-4-5z"/></svg>
      </div>
      <div class="hp-quote-body">
        <div class="hp-quote-text"><?= h($quoteOfDay['tekst']) ?></div>
        <div class="hp-quote-author">— <?= h($quoteOfDay['auteur']) ?></div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Sectie 3: procesflow -->
  <div class="hp-flow-card">
    <div class="hp-flow-head">
      <div class="hp-flow-title">Hoe werkt het?</div>
      <div class="hp-flow-rule" aria-hidden="true"></div>
      <div class="hp-flow-sub">5 stappen van idee tot keuze</div>
    </div>

    <div class="hp-flow-grid">
      <div class="hp-flow-line" aria-hidden="true"></div>
      <?php foreach ($steps as $s):
        $c = $s['color'];
        $shadow = "0 0 0 4px {$c}1a, 0 0 0 6px {$c}40";
      ?>
        <div class="hp-step">
          <div class="hp-step-badge" style="box-shadow: <?= $shadow ?>; color: <?= h($c) ?>;">
            <?= $s['icon'] ?>
          </div>
          <div class="hp-step-num" style="color: <?= h($c) ?>;"><?= h($s['num']) ?></div>
          <div class="hp-step-title"><?= h($s['title']) ?></div>
          <div class="hp-step-text"><?= h($s['text']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <style>
    /* Sectie 1 */
    .hp-greet { margin-bottom: 20px; }
    .hp-greet-title { font-size: 24px; font-weight: 800; letter-spacing: -.3px; margin: 0 0 4px; color: #0d1117; }
    .hp-greet-sub   { font-size: 13.5px; color: #6b7280; }

    /* Sectie 2: hero */
    .hp-hero {
      position: relative;
      background: linear-gradient(135deg, #0d2d3a 0%, #134e4a 100%);
      border-radius: 16px;
      padding: 36px 40px;
      overflow: hidden;
      display: flex; justify-content: space-between; align-items: center;
      gap: 32px;
    }
    .hp-hero-deco { position: absolute; border-radius: 50%; pointer-events: none; }
    .hp-hero-deco-tr {
      width: 240px; height: 240px; top: -80px; right: -60px;
      background: rgba(14,165,233,.12);
    }
    .hp-hero-deco-br {
      width: 180px; height: 180px; bottom: -60px; right: 180px;
      background: rgba(16,185,129,.08);
    }
    .hp-hero-left { position: relative; z-index: 1; max-width: 560px; }
    .hp-hero-pill {
      display: inline-block;
      background: rgba(14,165,233,.2);
      border: 1px solid rgba(14,165,233,.3);
      border-radius: 20px;
      padding: 4px 12px;
      color: #a5b4fc;
      font-size: 11px; font-weight: 700; letter-spacing: .06em;
      margin-bottom: 16px;
    }
    .hp-hero-title {
      font-size: 26px; font-weight: 800; color: #fff;
      letter-spacing: -.5px; line-height: 1.25;
      margin: 0 0 14px 0;
    }
    .hp-hero-lede {
      font-size: 13.5px; line-height: 1.65;
      color: rgba(255,255,255,.45);
      margin: 0;
    }

    .hp-hero-stats {
      position: relative; z-index: 1;
      display: flex;
      background: rgba(255,255,255,.05);
      border: 1px solid rgba(255,255,255,.08);
      border-radius: 14px;
      overflow: hidden;
      flex-shrink: 0;
    }
    .hp-stat {
      padding: 20px 28px;
      text-align: center;
      border-right: 1px solid rgba(255,255,255,.07);
    }
    .hp-stat:last-child { border-right: none; }
    .hp-stat-num {
      font-size: 36px; font-weight: 800;
      letter-spacing: -1.5px; line-height: 1.1;
      font-variant-numeric: tabular-nums;
    }
    .hp-stat-lbl {
      font-size: 11.5px; font-weight: 600;
      letter-spacing: .03em;
      color: rgba(255,255,255,.35);
      margin-top: 6px;
    }

    /* Quote van de dag */
    .hp-quote {
      background: #fff;
      border-radius: 16px;
      padding: 24px 32px;
      margin-top: 20px;
      display: flex; align-items: flex-start; gap: 20px;
      box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 16px rgba(0,0,0,.03);
      border: 1px solid rgba(0,0,0,.04);
    }
    .hp-quote-icon {
      flex-shrink: 0;
      width: 36px; height: 36px;
      color: #0891b2;
      opacity: .55;
    }
    .hp-quote-icon svg { width: 100%; height: 100%; }
    .hp-quote-body { flex: 1; }
    .hp-quote-text {
      font-size: 14.5px; line-height: 1.65;
      font-style: italic;
      color: #374151;
    }
    .hp-quote-author {
      font-size: 12.5px; color: #6b7280;
      margin-top: 8px;
    }

    /* Sectie 3: procesflow */
    .hp-flow-card {
      background: #fff;
      border-radius: 16px;
      padding: 32px 36px;
      margin-top: 20px;
      box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 16px rgba(0,0,0,.03);
      border: 1px solid rgba(0,0,0,.04);
    }
    .hp-flow-head {
      display: flex; align-items: center; gap: 16px;
      margin-bottom: 28px;
    }
    .hp-flow-title { font-size: 16px; font-weight: 800; color: #0d1117; }
    .hp-flow-rule  { flex: 1; height: 1px; background: var(--border, #e5e8ef); }
    .hp-flow-sub   { font-size: 12px; color: #6b7280; }

    .hp-flow-grid {
      position: relative;
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 16px;
    }
    .hp-flow-line {
      position: absolute;
      top: 28px; left: 10%; right: 10%;
      height: 2px;
      background: linear-gradient(90deg,
        rgba(14,165,233,.3), rgba(245,158,11,.3),
        rgba(59,130,246,.3), rgba(236,72,153,.3),
        rgba(16,185,129,.3));
      z-index: 0;
    }

    .hp-step {
      position: relative; z-index: 1;
      display: flex; flex-direction: column; align-items: center;
      text-align: center;
      color: inherit;
    }
    .hp-step-badge {
      width: 56px; height: 56px; border-radius: 50%;
      background: #fff;
      display: flex; align-items: center; justify-content: center;
      margin-bottom: 18px;
    }
    .hp-step-num {
      font-size: 10px; font-weight: 800;
      letter-spacing: .08em;
      margin-bottom: 6px;
    }
    .hp-step-title {
      font-size: 14px; font-weight: 800; color: #0d1117;
      margin-bottom: 6px;
    }
    .hp-step-text {
      font-size: 12px; line-height: 1.6;
      color: #6b7280;
    }

    @media (max-width: 960px) {
      .hp-hero { flex-direction: column; align-items: stretch; padding: 26px; }
      .hp-hero-title { font-size: 22px; }
      .hp-hero-stats { align-self: stretch; }
      .hp-flow-grid  { grid-template-columns: repeat(2, 1fr); }
      .hp-flow-line  { display: none; }
    }
    @media (max-width: 560px) {
      .hp-hero-stats { flex-direction: column; }
      .hp-stat { border-right: none; border-bottom: 1px solid rgba(255,255,255,.07); }
      .hp-stat:last-child { border-bottom: none; }
      .hp-flow-grid  { grid-template-columns: 1fr; }
    }
  </style>
<?php };

require __DIR__ . '/../templates/layout.php';
