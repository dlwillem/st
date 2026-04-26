<?php
/**
 * Requirements — lijst + nieuw (per huidig traject).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/trajecten.php';
require_once __DIR__ . '/../includes/requirements.php';
require_login();

$canEdit = can('requirements.edit');

// Traject-context via ?traject_id of sessie — geen redirect.
$urlTrajectId = input_int('traject_id');
if ($urlTrajectId && can_view_traject($urlTrajectId)) {
    set_current_traject($urlTrajectId);
}
$trajectId = current_traject_id();
if ($trajectId && !can_view_traject((int)$trajectId)) $trajectId = null;
$traject   = $trajectId ? db_one('SELECT * FROM trajecten WHERE id = :id', [':id' => $trajectId]) : null;
if (!$traject) $trajectId = null;

// ─── POST ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    if (!$canEdit) { http_response_code(403); exit('Onvoldoende rechten.'); }

    $action = input_str('action');
    try {
        if ($action === 'create') {
            $title = input_str('title');
            $sid   = input_int('subcategorie_id');
            if ($title === '' || !$sid) {
                throw new RuntimeException('Titel en subcategorie zijn verplicht.');
            }
            requirement_create($trajectId, [
                'subcategorie_id' => $sid,
                'title'           => $title,
                'description'     => input_str('description'),
                'type'            => input_str('type', 'eis'),
            ]);
            flash_set('success', 'Requirement toegevoegd.');
        } elseif ($action === 'delete') {
            $rid = input_int('id');
            if ($rid) {
                requirement_delete($rid, $trajectId);
                flash_set('success', 'Requirement verwijderd.');
            }
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect('pages/requirements.php');
}

// ─── GET ─────────────────────────────────────────────────────────────────────
$filters = [
    'q'               => input_str('q'),
    'type'            => input_str('type'),
    'cat_code'        => (isset($_GET['cat_code']) ? input_str('cat_code') : 'FUNC'),
    'subcategorie_id' => input_int('subcategorie_id', 0) ?: null,
];
// Voor tab-counts: haal rijen op zonder cat_code-filter (wel met q/type-filters)
$filtersForCounts = $filters; $filtersForCounts['cat_code'] = '';
$allRows = $trajectId ? requirements_list($trajectId, $filtersForCounts) : [];
$rows    = $filters['cat_code'] !== ''
    ? array_values(array_filter($allRows, fn($r) => $r['cat_code'] === $filters['cat_code']))
    : $allRows;
$subcats = $trajectId ? requirement_subcats_for_traject($trajectId) : [];

$allowedIds = user_allowed_traject_ids();
$scopeClause = '';
if ($allowedIds !== null) {
    $scopeClause = empty($allowedIds)
        ? ' AND 0'
        : ' AND id IN (' . implode(',', array_map('intval', $allowedIds)) . ')';
}
$selectableTrajecten = db_all(
    "SELECT id, name, status FROM trajecten
      WHERE status IN ('concept','actief')" . $scopeClause . "
      ORDER BY CASE status WHEN 'actief' THEN 0 ELSE 1 END, name ASC"
);

// Groepeer subcats per hoofdcategorie voor dropdown + filters
$subsGrouped = [];
$catCodes    = [];
foreach ($subcats as $s) {
    $subsGrouped[$s['cat_code']]['name'] = $s['cat_name'];
    $subsGrouped[$s['cat_code']]['subs'][] = $s;
    $catCodes[$s['cat_code']] = $s['cat_name'];
}

// Tab-volgorde + titels (vast, matcht repository)
$tabOrder = [
    'FUNC' => 'Functionele requirements',
    'NFR'  => 'Non functionele requirements',
    'VEND' => 'Leverancier',
    'IMPL' => 'Implementatie',
    'SUP'  => 'Support',
    'LIC'  => 'Licentiemodel',
];
// Tel requirements per cat_code voor badges
$countsPerCat = [];
foreach ($allRows as $r) { $countsPerCat[$r['cat_code']] = ($countsPerCat[$r['cat_code']] ?? 0) + 1; }

// Groepeer requirements per hoofdcategorie → subcategorie voor lijstweergave
$grouped = [];
foreach ($rows as $r) {
    $grouped[$r['cat_code']]['name'] = $r['cat_name'];
    $grouped[$r['cat_code']]['subs'][$r['subcategorie_id']]['name'] = $r['sub_name'];
    $grouped[$r['cat_code']]['subs'][$r['subcategorie_id']]['reqs'][] = $r;
}

$pageTitle  = 'Requirements';
$currentNav = 'requirements';

$bodyRenderer = function () use (
    $traject, $trajectId, $rows, $grouped, $subsGrouped, $catCodes,
    $filters, $canEdit, $selectableTrajecten, $tabOrder, $countsPerCat
) { ?>

  <?php
    // Categorie-kleuren (hex) — voor pills, blok-headers en accenten
    $catColors = [
      'FUNC' => ['hex' => '#3b82f6', 'bg' => 'rgba(59,130,246,.07)',  'border' => 'rgba(59,130,246,.15)',  'pillBg' => 'rgba(59,130,246,.10)',  'pillFg' => '#2563eb'],
      'NFR'  => ['hex' => '#f59e0b', 'bg' => 'rgba(245,158,11,.07)',  'border' => 'rgba(245,158,11,.18)',  'pillBg' => 'rgba(245,158,11,.12)',  'pillFg' => '#b45309'],
      'VEND' => ['hex' => '#10b981', 'bg' => 'rgba(16,185,129,.07)',  'border' => 'rgba(16,185,129,.18)',  'pillBg' => 'rgba(16,185,129,.12)',  'pillFg' => '#059669'],
      'IMPL' => ['hex' => '#06b6d4', 'bg' => 'rgba(6,182,212,.07)',   'border' => 'rgba(6,182,212,.18)',   'pillBg' => 'rgba(6,182,212,.12)',   'pillFg' => '#0e7490'],
      'SUP'  => ['hex' => '#8b5cf6', 'bg' => 'rgba(139,92,246,.07)',  'border' => 'rgba(139,92,246,.18)',  'pillBg' => 'rgba(139,92,246,.12)',  'pillFg' => '#7c3aed'],
      'LIC'  => ['hex' => '#ef4444', 'bg' => 'rgba(239,68,68,.07)',   'border' => 'rgba(239,68,68,.18)',   'pillBg' => 'rgba(239,68,68,.10)',   'pillFg' => '#dc2626'],
    ];
    $baseQs = array_filter(['q' => $filters['q']], fn($v) => $v !== '' && $v !== null);
    $mkUrl = function ($code) use ($baseQs) {
        $qs = $baseQs;
        if ($code !== '') $qs['cat_code'] = $code;
        return APP_BASE_URL . '/pages/requirements.php' . ($qs ? '?' . http_build_query($qs) : '');
    };
    $totalAll = array_sum($countsPerCat);
    $moscowLabel = fn($t) => ['eis' => 'MUST', 'wens' => 'SHOULD', 'ko' => 'KNOCK-OUT'][$t] ?? strtoupper($t);
  ?>

  <div class="page-header" style="margin-bottom:18px;">
    <div>
      <h1 style="margin:0;">Requirements</h1>
      <p class="muted small" style="margin:4px 0 0;">
        <?php if ($traject): ?>
          <?= count($rows) ?> requirement<?= count($rows) === 1 ? '' : 's' ?> · <?= h($traject['name']) ?>
        <?php else: ?>
          Kies een traject om requirements te bekijken.
        <?php endif; ?>
      </p>
    </div>
    <div class="actions">
      <?php if ($traject && $selectableTrajecten): ?>
        <form method="get" style="margin:0;">
          <label style="display:inline-flex;align-items:center;gap:6px;">
            <span class="muted small">Traject:</span>
            <select name="traject_id" class="input" style="margin-top:0;width:auto;min-width:220px;" onchange="this.form.submit()">
              <?php foreach ($selectableTrajecten as $tr): ?>
                <option value="<?= (int)$tr['id'] ?>" <?= (int)$tr['id'] === (int)$trajectId ? 'selected' : '' ?>>
                  <?= h($tr['name']) ?><?= $tr['status'] === 'concept' ? ' (concept)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        </form>
      <?php endif; ?>
      <?php if ($traject): ?>
        <a class="btn ghost" href="<?= h(APP_BASE_URL) ?>/pages/requirements_tools.php?action=export">
          <?= icon('download', 14) ?> Exporteren
        </a>
      <?php endif; ?>
      <?php if ($canEdit && $traject): ?>
        <a class="btn ghost" href="<?= h(APP_BASE_URL) ?>/pages/requirements_tools.php">
          <?= icon('upload', 14) ?> Uploaden
        </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$traject): ?>
    <form method="get" class="card card-compact" style="margin-bottom:16px;">
      <label class="field" style="margin-bottom:0;">
        Actief traject
        <select name="traject_id" class="input" onchange="this.form.submit()">
          <option value="">— kies een traject —</option>
          <?php foreach ($selectableTrajecten as $tr): ?>
            <option value="<?= (int)$tr['id'] ?>"><?= h($tr['name']) ?><?= $tr['status'] === 'concept' ? ' (concept)' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>
    <div class="card center" style="padding:40px 20px;">
      <p style="font-size:2.5rem;margin:0 0 8px;">📁</p>
      <p class="strong">Geen traject gekozen</p>
      <p class="muted small">Kies hierboven een traject om de requirements te zien.</p>
    </div>
    <?php return; ?>
  <?php endif; ?>

  <!-- Zoekbalk -->
  <form method="get" class="req-search-wrap">
    <input type="hidden" name="cat_code" value="<?= h($filters['cat_code']) ?>">
    <div class="req-search">
      <?= icon('search', 16) ?>
      <input type="text" name="q" value="<?= h($filters['q']) ?>"
             placeholder="Zoek op titel of omschrijving…"
             oninput="clearTimeout(window.__reqSearchT);window.__reqSearchT=setTimeout(()=>this.form.submit(),300);">
    </div>
  </form>

  <!-- Categorie-pills -->
  <div class="req-pills">
    <?php foreach ($tabOrder as $code => $title):
      $active = ($filters['cat_code'] === $code);
      $c      = $catColors[$code] ?? ['hex' => '#6b7280'];
      $n      = (int)($countsPerCat[$code] ?? 0);
    ?>
      <a href="<?= h($mkUrl($code)) ?>"
         class="req-pill<?= $active ? ' active' : '' ?>"
         style="<?= $active ? '--pill:' . h($c['hex']) . ';background:' . h($c['hex']) . ';border-color:' . h($c['hex']) . ';color:#fff;' : '' ?>">
        <?= h($code) ?> — <?= h($title) ?>
        <span class="req-pill-count"><?= $n ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <?php
    // Render altijd alle bekende hoofdcategorieën uit $tabOrder.
    // Categorieën zónder stamdata krijgen een "admin contacteren"-melding.
    $blocksToRender = $tabOrder;
    if ($filters['cat_code'] !== '' && isset($blocksToRender[$filters['cat_code']])) {
        $blocksToRender = [$filters['cat_code'] => $blocksToRender[$filters['cat_code']]];
    }
    // Naam van de stamdata-eenheid per categorie (voor lege-stamdata-melding)
    $stamUnitLabel = [
        'FUNC' => ['singular' => 'app service', 'plural' => 'app services'],
        'NFR'  => ['singular' => 'domein',      'plural' => 'domeinen'],
        'VEND' => ['singular' => "thema",       'plural' => "thema's"],
        'IMPL' => ['singular' => "thema",       'plural' => "thema's"],
        'SUP'  => ['singular' => "thema",       'plural' => "thema's"],
        'LIC'  => ['singular' => "thema",       'plural' => "thema's"],
    ];
  ?>

  <?php foreach ($blocksToRender as $code => $title):
      $c        = $catColors[$code] ?? ['hex' => '#6b7280', 'bg' => '#f3f4f6', 'border' => '#e5e7eb', 'pillBg' => '#e5e7eb', 'pillFg' => '#374151'];
      $g        = $grouped[$code] ?? ['name' => $title, 'subs' => []];
      $hasStam  = !empty($subsGrouped[$code]['subs']);
      $cnt      = 0;
      foreach ($g['subs'] as $sub) $cnt += count($sub['reqs']);
      $unit     = $stamUnitLabel[$code] ?? ['singular' => 'subcategorie', 'plural' => 'subcategorieën'];
    ?>
      <div class="req-cat" style="border:1px solid <?= h($c['border']) ?>;">
        <div class="req-cat-head" style="background:<?= h($c['bg']) ?>;border-bottom:1px solid <?= h($c['border']) ?>;">
          <span class="req-cpill" style="background:<?= h($c['pillBg']) ?>;color:<?= h($c['pillFg']) ?>;"><?= h($code) ?></span>
          <h2 class="req-cat-title"><?= h($title) ?></h2>
          <span class="req-cat-count" style="color:<?= h($c['pillFg']) ?>;"><?= $cnt ?> requirement<?= $cnt === 1 ? '' : 's' ?></span>
          <?php if ($canEdit): ?>
            <?php if ($hasStam): ?>
              <button type="button" class="req-cat-add"
                      style="background:<?= h($c['hex']) ?>;"
                      onclick="reqOpenModal('<?= h($code) ?>')">
                <?= icon('plus', 14) ?> Nieuw requirement
              </button>
            <?php else: ?>
              <button type="button" class="req-cat-add" disabled
                      title="Geen <?= h($unit['plural']) ?> beschikbaar voor dit traject — vraag een admin de stamdata toe te voegen.">
                <?= icon('plus', 14) ?> Nieuw requirement
              </button>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <?php if (!$hasStam): ?>
          <div class="req-stam-missing">
            <strong>Nog geen <?= h($unit['plural']) ?> beschikbaar voor dit traject.</strong>
            <span>De sub-structuur ontbreekt nog voor categorie <?= h($code) ?>. Neem contact op met een admin zodat de <?= h($unit['plural']) ?> via <em>Structuur stamdata</em> aan de applicatie worden toegevoegd.</span>
          </div>
        <?php elseif (!$g['subs']): ?>
          <div class="req-empty">Nog geen requirements in deze categorie.</div>
        <?php else: foreach ($g['subs'] as $subId => $sub): ?>
          <div class="req-sub-label"><?= h($sub['name']) ?></div>
          <?php foreach ($sub['reqs'] as $r):
            $href = APP_BASE_URL . '/pages/requirement_edit.php?id=' . (int)$r['id'];
            $mLabel = $moscowLabel($r['type']);
            $isKO   = $r['type'] === 'ko';
          ?>
            <div class="req-row row-link" data-href="<?= h($href) ?>">
              <div class="req-row-main">
                <div class="req-row-title"><?= h($r['title']) ?></div>
                <?php if ($r['description']): ?>
                  <div class="req-row-desc"><?= h(mb_strimwidth((string)$r['description'], 0, 180, '…')) ?></div>
                <?php endif; ?>
              </div>
              <div class="req-row-meta" data-no-rowlink>
                <span class="req-moscow<?= $isKO ? ' ko' : '' ?>"><?= h($mLabel) ?></span>
                <code class="req-code" title="<?= h($r['code']) ?>"><?= h($r['code']) ?></code>
                <?php if ($canEdit): ?>
                  <form method="post" class="req-del" onsubmit="return confirm('Requirement <?= h($r['code']) ?> verwijderen?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="req-del-btn" title="Verwijderen" style="--del:<?= h($c['hex']) ?>;">
                      <?= icon('trash', 14) ?>
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endforeach; endif; ?>
      </div>
    <?php endforeach; ?>

  <style>
    .req-search-wrap { margin: 0 0 14px; }
    .req-search {
      display:flex; align-items:center; gap:10px;
      background:#fff; border:1px solid var(--border, #e5e8ef);
      border-radius:12px; padding:12px 16px;
      box-shadow: 0 1px 3px rgba(0,0,0,.04);
    }
    .req-search svg { color:#9ca3af; flex-shrink:0; }
    .req-search input {
      flex:1; border:none; outline:none; background:transparent;
      font-size:14px; color:#111827;
      font-family: inherit;
    }
    .req-search input::placeholder { color:#9ca3af; }

    .req-pills {
      display:flex; flex-wrap:wrap; gap:8px;
      margin-bottom:18px;
    }
    .req-pill {
      display:inline-flex; align-items:center; gap:8px;
      padding:8px 14px; border-radius:10px;
      background:#fff; border:1.5px solid var(--border, #e5e8ef);
      color:#374151; font-size:13px; font-weight:600;
      text-decoration:none;
      transition: transform .12s, box-shadow .12s, border-color .12s;
    }
    .req-pill:hover:not(.active) { border-color:#d1d5db; transform:translateY(-1px); }
    .req-pill.active { box-shadow: 0 4px 14px rgba(0,0,0,.08); }
    .req-pill-count {
      background: rgba(0,0,0,.08);
      color: inherit;
      font-size:11px; font-weight:700;
      padding:1px 7px; border-radius:10px;
      min-width:20px; text-align:center;
    }
    .req-pill.active .req-pill-count {
      background: rgba(0,0,0,.25);
      color:#fff;
    }

    .req-cat {
      background:#fff;
      border-radius:14px;
      overflow:hidden;
      box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 16px rgba(0,0,0,.04);
      margin-bottom:18px;
    }
    .req-cat-head {
      display:flex; align-items:center; gap:12px;
      padding:14px 20px;
    }
    .req-cpill {
      display:inline-flex; align-items:center;
      padding:3px 9px; border-radius:6px;
      font-size:11px; font-weight:700; letter-spacing:.03em;
    }
    .req-cat-title {
      margin:0; font-size:15px; font-weight:700; color:#111827;
      flex:1;
    }
    .req-cat-count {
      font-size:12.5px; font-weight:600;
    }

    .req-sub-label {
      padding:10px 20px 6px;
      border-top:1px solid #f3f4f6;
      font-size:12px; font-weight:600; color:#9ca3af;
    }
    .req-sub-label:first-of-type { border-top:none; }

    .req-row {
      display:flex; align-items:flex-start; justify-content:space-between;
      gap:16px;
      padding:12px 20px 14px 28px;
      border-top:1px solid #f8f9fa;
      cursor:pointer;
      transition: background .1s;
    }
    .req-row:hover { background:#fafbfc; }
    .req-row-main { flex:1; min-width:0; }
    .req-row-title {
      font-size:14.5px; font-weight:700; color:#111827;
      line-height:1.35; margin-bottom:4px;
    }
    .req-row-desc {
      font-size:13px; color:#6b7280; line-height:1.6;
    }

    .req-row-meta {
      display:flex; align-items:center; gap:14px;
      flex-shrink:0; padding-top:2px;
    }
    .req-moscow {
      font-size:11px; font-weight:700; letter-spacing:.04em;
      color:#d1d5db;
    }
    .req-moscow.ko { color:#dc2626; font-weight:800; }

    .req-code {
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
      font-size:10.5px; color:#d1d5db; font-weight:600;
    }

    .req-del { margin:0; display:inline-flex; }
    .req-del-btn {
      border:none; background:transparent; cursor:pointer;
      color:#d1d5db; padding:4px; border-radius:6px;
      opacity:.35; transition: opacity .12s, color .12s, background .12s;
      display:inline-flex;
    }
    .req-row:hover .req-del-btn { opacity:1; color: var(--del, #ef4444); }
    .req-del-btn:hover { background: rgba(239,68,68,.08); }

    /* Per-categorie "+ Nieuw requirement"-knop in cat-head */
    .req-cat-add {
      display:inline-flex; align-items:center; gap:6px;
      padding:7px 12px; border-radius:8px;
      border:0; cursor:pointer;
      color:#fff; font:inherit; font-size:12.5px; font-weight:600;
      transition: filter .12s, transform .12s;
    }
    .req-cat-add:hover:not(:disabled) { filter: brightness(.92); transform: translateY(-1px); }
    .req-cat-add svg { width:14px; height:14px; }
    .req-cat-add:disabled { background:#cbd5e1 !important; cursor:not-allowed; opacity:.7; }

    /* Stamdata-ontbreekt-melding binnen een categorie-blok */
    .req-stam-missing {
      padding: 18px 20px;
      display:flex; flex-direction:column; gap:4px;
      background:#fffbeb;
      border-top:1px solid #fde68a;
      color:#78350f;
      font-size:13px; line-height:1.5;
    }
    .req-stam-missing strong { color:#78350f; font-weight:700; }
    .req-stam-missing em { font-style:normal; font-weight:600; color:#92400e; }

    /* Lege categorie-melding (binnen een blok zonder requirements) */
    .req-empty {
      padding: 22px 20px;
      color:#9ca3af; font-size:13px; font-style:italic; text-align:center;
    }

    /* Brede modal voor Nieuw requirement (1,5× standaard) */
    #new-req-modal .modal { max-width: 720px; width: 100%; }
  </style>

  <?php if ($canEdit): ?>
    <!-- Nieuw-requirement modal -->
    <div id="new-req-modal" class="modal-backdrop" style="display:none;"
         onclick="if(event.target===this)this.style.display='none'">
      <div class="modal">
        <div class="modal-header">
          <h2 id="new-req-title">Nieuw requirement</h2>
          <button type="button" class="btn-icon"
                  onclick="document.getElementById('new-req-modal').style.display='none'">
            <?= icon('x', 16) ?>
          </button>
        </div>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <div class="modal-body">
            <label class="field">Titel
              <input type="text" name="title" required maxlength="300" autofocus>
            </label>
            <label class="field">Omschrijving
              <textarea name="description" maxlength="4000"
                        placeholder="Optioneel — toelichting, acceptatiecriteria…"></textarea>
            </label>
            <div class="field-row">
              <label class="field">
                <span id="new-req-sub-label">Subcategorie</span>
                <select name="subcategorie_id" id="new-req-sub" class="input" required>
                  <option value="">Kies…</option>
                </select>
              </label>
              <label class="field">MoSCoW
                <select name="type" class="input">
                  <?php foreach (REQUIREMENT_TYPES as $t): ?>
                    <option value="<?= h($t) ?>" <?= $t === 'eis' ? 'selected' : '' ?>>
                      <?= h(requirement_type_label($t)) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn ghost"
                    onclick="document.getElementById('new-req-modal').style.display='none'">
              Annuleren
            </button>
            <button type="submit" class="btn">Aanmaken</button>
          </div>
        </form>
      </div>
    </div>

    <script>
      window.__reqSubs = <?= json_encode(array_map(
          fn($g) => ['name' => $g['name'], 'subs' => array_map(
              fn($s) => ['id' => (int)$s['id'], 'name' => $s['name']],
              $g['subs']
          )],
          $subsGrouped
      ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      window.__reqLabels = {
        FUNC: { field: 'App service', placeholder: 'Kies app service…' },
        NFR:  { field: 'Domein',      placeholder: 'Kies domein…' },
        VEND: { field: 'Thema',       placeholder: 'Kies thema…' },
        IMPL: { field: 'Thema',       placeholder: 'Kies thema…' },
        SUP:  { field: 'Thema',       placeholder: 'Kies thema…' },
        LIC:  { field: 'Thema',       placeholder: 'Kies thema…' }
      };
      window.reqOpenModal = function (code) {
        const m  = document.getElementById('new-req-modal');
        const sel = document.getElementById('new-req-sub');
        const lbl = document.getElementById('new-req-sub-label');
        const ttl = document.getElementById('new-req-title');
        const labels = window.__reqLabels[code] || { field: 'Subcategorie', placeholder: 'Kies…' };
        const data = window.__reqSubs[code] || { name: '', subs: [] };
        if (!data.subs || data.subs.length === 0) {
          alert('Voor categorie ' + code + ' zijn nog geen ' + labels.field.toLowerCase() + 's beschikbaar in dit traject. Vraag een admin om de stamdata aan te vullen.');
          return;
        }
        lbl.textContent = labels.field;
        ttl.textContent = 'Nieuw requirement — ' + (data.name || code);
        sel.innerHTML = '<option value="">' + labels.placeholder + '</option>' +
          data.subs.map(s => '<option value="' + s.id + '">' + s.name.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</option>').join('');
        m.style.display = 'flex';
        // Reset andere velden bij elke open
        m.querySelector('input[name="title"]').value = '';
        m.querySelector('textarea[name="description"]').value = '';
        m.querySelector('select[name="type"]').value = 'eis';
        setTimeout(() => m.querySelector('input[name="title"]').focus(), 50);
      };
    </script>
  <?php endif; ?>

<?php };

require __DIR__ . '/../templates/layout.php';
