<?php
/**
 * Nieuw traject — guided wizard in 6 stappen:
 *   1. Metadata          (naam, omschrijving, data, status)
 *   2. Applicatieservices (FUNC subcat-templates, per applicatiesoort)
 *   3. NFR-domeinen
 *   4. VEND-thema's
 *   5. LIC-thema's
 *   6. SUP-thema's
 *
 * Alle stappen leven in één <form>; JS schakelt de panelen. Pas bij submit
 * op stap 6 wordt het traject aangemaakt.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/trajecten.php';
require_once __DIR__ . '/../includes/applicatiesoorten.php';
require_once __DIR__ . '/../includes/requirements.php';
require_login();
require_can('trajecten.edit');

// ─── POST: aanmaken ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $name        = input_str('name');
    $description = input_str('description');
    $startDate   = input_str('start_date');
    $endDate     = input_str('end_date');
    $status      = input_str('status', 'concept');
    $tplIds      = array_values(array_unique(array_map('intval', (array)($_POST['templates'] ?? []))));

    try {
        if ($name === '')                                  throw new RuntimeException('Naam is verplicht.');
        if (!in_array($status, TRAJECT_STATUSES, true))    throw new RuntimeException('Ongeldige status.');

        $id = traject_create($name, $description, $startDate, $endDate, $status, [], $tplIds);
        require_once __DIR__ . '/../includes/demo_catalog.php';
        demo_catalog_copy_from_master($id);
        set_current_traject($id);
        flash_set('success', 'Traject "' . $name . '" aangemaakt.');
        redirect('pages/traject_detail.php?id=' . $id);
    } catch (Throwable $e) {
        flash_set('error', 'Aanmaken mislukt: ' . $e->getMessage());
    }
}

// ─── Data voor wizard-panelen ───────────────────────────────────────────────
// FUNC applicatieservices, gegroepeerd per applicatiesoort
$funcRows = db_all(
    "SELECT t.id, t.name, t.applicatiesoort_id,
            a.label AS app_label, a.description AS app_description
       FROM subcategorie_templates t
       JOIN categorieen c ON c.id = t.categorie_id
       LEFT JOIN applicatiesoorten a ON a.id = t.applicatiesoort_id
      WHERE c.code = 'FUNC'
      ORDER BY a.sort_order, a.label, t.sort_order, t.name"
);
$funcByApp = []; // label => [templates]
foreach ($funcRows as $r) {
    $key = $r['app_label'] ?? '— zonder applicatiesoort —';
    $funcByApp[$key]['description'] = $r['app_description'] ?? '';
    $funcByApp[$key]['templates'][] = $r;
}

// Platte templates per flat-categorie
$flatTpls = [];
foreach (['NFR', 'VEND', 'LIC', 'SUP'] as $code) {
    $catId = (int)db_value('SELECT id FROM categorieen WHERE code = :c', [':c' => $code]);
    $flatTpls[$code] = $catId ? db_all(
        "SELECT id, name FROM subcategorie_templates
          WHERE categorie_id = :c
          ORDER BY sort_order, name",
        [':c' => $catId]
    ) : [];
}

$steps = [
    ['n' => 1, 'code' => null,   'title' => 'Basisgegevens'],
    ['n' => 2, 'code' => 'FUNC', 'title' => 'Applicatieservices'],
    ['n' => 3, 'code' => 'NFR',  'title' => 'NFR-domeinen'],
    ['n' => 4, 'code' => 'VEND', 'title' => 'VEND-thema\'s'],
    ['n' => 5, 'code' => 'LIC',  'title' => 'LIC-thema\'s'],
    ['n' => 6, 'code' => 'SUP',  'title' => 'SUP-thema\'s'],
];

$pageTitle  = 'Nieuw traject';
$currentNav = 'trajecten';

$bodyRenderer = function () use ($steps, $funcByApp, $flatTpls) { ?>

  <div class="page-header">
    <div>
      <div class="row-sm" style="align-items:center;margin-bottom:4px;">
        <a href="<?= h(APP_BASE_URL) ?>/pages/trajecten.php" class="muted small">← Trajecten</a>
      </div>
      <h1>Nieuw selectietraject</h1>
      <p>Doorloop de stappen om een traject in te richten.</p>
    </div>
  </div>

  <!-- Stap-indicator -->
  <div id="wizard-steps" class="card card-compact" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px;padding:10px 14px;">
    <?php foreach ($steps as $s):
      $col = $s['code'] ? 'var(--' . requirement_cat_style($s['code'])['color'] . '-600)' : 'var(--indigo-600)';
    ?>
      <div class="wz-chip" data-step="<?= (int)$s['n'] ?>" data-col="<?= h($col) ?>"
           style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:20px;border:1px solid var(--gray-200);font-size:0.8125rem;color:var(--gray-500);">
        <span class="wz-num" style="width:20px;height:20px;border-radius:50%;background:var(--gray-200);color:var(--gray-600);display:inline-flex;align-items:center;justify-content:center;font-weight:600;font-size:0.75rem;"><?= (int)$s['n'] ?></span>
        <span><?= h($s['title']) ?></span>
      </div>
      <?php if ($s['n'] < count($steps)): ?>
        <span style="color:var(--gray-300);">›</span>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <form method="post" autocomplete="off" id="wizard-form">
    <?= csrf_field() ?>

    <!-- Stap 1 — Basisgegevens -->
    <section class="wz-panel card" data-step="1">
      <h2 style="margin-top:0;">1. Basisgegevens</h2>
      <label class="field">Naam
        <input type="text" class="input" name="name" required maxlength="200" autofocus>
      </label>
      <label class="field">Omschrijving
        <textarea class="input" name="description" maxlength="2000" rows="3"></textarea>
      </label>
      <div class="field-row">
        <label class="field">Startdatum
          <input type="date" class="input" name="start_date">
        </label>
        <label class="field">Einddatum
          <input type="date" class="input" name="end_date">
        </label>
      </div>
      <label class="field">Status
        <select name="status" class="input">
          <option value="concept" selected>Concept</option>
          <option value="actief">Actief</option>
        </select>
      </label>
    </section>

    <!-- Stap 2 — FUNC applicatieservices -->
    <?php $styleFunc = requirement_cat_style('FUNC'); $colFunc = 'var(--' . $styleFunc['color'] . '-600)'; ?>
    <section class="wz-panel card" data-step="2" style="display:none;border-top:3px solid <?= h($colFunc) ?>;">
      <div class="row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <h2 style="margin:0;display:inline-flex;align-items:center;gap:8px;">
          <span style="color:<?= h($colFunc) ?>;display:inline-flex;"><?= icon($styleFunc['icon'], 18) ?></span>
          2. Applicatieservices
        </h2>
        <div class="row-sm" style="gap:6px;">
          <button type="button" class="btn sm ghost" onclick="wzCheckAll('FUNC', true)">Alles</button>
          <button type="button" class="btn sm ghost" onclick="wzCheckAll('FUNC', false)">Niets</button>
        </div>
      </div>
      <p class="muted small">Kies welke applicatieservices in scope zijn. De bijbehorende applicatiesoort staat subtiel vermeld.</p>

      <div data-tplgroup="FUNC" class="card card-compact" style="max-height:440px;overflow:auto;padding:0;margin-top:6px;">
        <?php if (!$funcByApp): ?>
          <p class="muted small" style="margin:16px;">Nog geen applicatieservices. Voeg ze toe via <a href="<?= h(APP_BASE_URL) ?>/pages/repository.php?tab=FUNC">Structuur stamdata</a>.</p>
        <?php else: ?>
          <?php foreach ($funcByApp as $appLabel => $grp): ?>
            <div style="padding:10px 14px;border-bottom:1px solid var(--gray-100);">
              <div class="muted small" title="<?= h($grp['description']) ?>"
                   style="font-weight:600;color:var(--gray-500);margin-bottom:6px;cursor:<?= $grp['description'] ? 'help' : 'default' ?>;">
                <?= h($appLabel) ?>
              </div>
              <?php foreach ($grp['templates'] as $t): ?>
                <label style="display:flex;align-items:center;gap:8px;padding:3px 0;cursor:pointer;font-size:0.875rem;">
                  <input type="checkbox" name="templates[]" value="<?= (int)$t['id'] ?>">
                  <span><?= h($t['name']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <!-- Stap 3..6 — NFR/VEND/LIC/SUP -->
    <?php
      $flatMeta = [
        'NFR'  => ['step' => 3, 'title' => '3. NFR-domeinen',      'intro' => 'Kies de relevante non-functionele domeinen.'],
        'VEND' => ['step' => 4, 'title' => '4. VEND-thema\'s',     'intro' => 'Kies de relevante thema\'s voor leveranciers.'],
        'LIC'  => ['step' => 5, 'title' => '5. LIC-thema\'s',      'intro' => 'Kies de relevante licentiemodel-thema\'s.'],
        'SUP'  => ['step' => 6, 'title' => '6. SUP-thema\'s',      'intro' => 'Kies de relevante support-thema\'s.'],
      ];
      foreach ($flatMeta as $code => $fm):
        $st  = requirement_cat_style($code);
        $col = 'var(--' . $st['color'] . '-600)';
        $tpls = $flatTpls[$code];
    ?>
      <section class="wz-panel card" data-step="<?= (int)$fm['step'] ?>" style="display:none;border-top:3px solid <?= h($col) ?>;">
        <div class="row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
          <h2 style="margin:0;display:inline-flex;align-items:center;gap:8px;">
            <span style="color:<?= h($col) ?>;display:inline-flex;"><?= icon($st['icon'], 18) ?></span>
            <?= h($fm['title']) ?>
          </h2>
          <?php if ($tpls): ?>
            <div class="row-sm" style="gap:6px;">
              <button type="button" class="btn sm ghost" onclick="wzCheckAll('<?= h($code) ?>', true)">Alles</button>
              <button type="button" class="btn sm ghost" onclick="wzCheckAll('<?= h($code) ?>', false)">Niets</button>
            </div>
          <?php endif; ?>
        </div>
        <p class="muted small"><?= h($fm['intro']) ?></p>

        <div data-tplgroup="<?= h($code) ?>" class="card card-compact" style="max-height:380px;overflow:auto;padding:10px;margin-top:6px;">
          <?php if (!$tpls): ?>
            <p class="muted small" style="margin:0;">
              Nog geen templates voor <?= h($code) ?>. Voeg toe via
              <a href="<?= h(APP_BASE_URL) ?>/pages/repository.php?tab=<?= h($code) ?>">Structuur stamdata</a>.
            </p>
          <?php else: ?>
            <?php foreach ($tpls as $t): ?>
              <label style="display:flex;align-items:center;gap:8px;padding:4px 0;cursor:pointer;font-size:0.875rem;">
                <input type="checkbox" name="templates[]" value="<?= (int)$t['id'] ?>" checked>
                <span><?= h($t['name']) ?></span>
              </label>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </section>
    <?php endforeach; ?>

    <!-- Navigatie -->
    <div class="card card-compact" style="display:flex;justify-content:space-between;gap:10px;margin-top:14px;padding:12px 14px;align-items:center;">
      <div>
        <a href="<?= h(APP_BASE_URL) ?>/pages/trajecten.php" class="btn ghost">Annuleren</a>
      </div>
      <div class="row-sm" style="gap:8px;">
        <button type="button" id="wz-prev" class="btn ghost" disabled>← Vorige</button>
        <button type="button" id="wz-next" class="btn">Volgende →</button>
        <button type="submit" id="wz-submit" class="btn" style="display:none;">Traject aanmaken</button>
      </div>
    </div>
  </form>

  <script>
    (function () {
      const steps = <?= count($steps) ?>;
      let cur = 1;
      const panels = document.querySelectorAll('.wz-panel');
      const chips  = document.querySelectorAll('#wizard-steps .wz-chip');
      const btnPrev = document.getElementById('wz-prev');
      const btnNext = document.getElementById('wz-next');
      const btnSub  = document.getElementById('wz-submit');
      const form    = document.getElementById('wizard-form');

      function show(n) {
        cur = Math.max(1, Math.min(steps, n));
        panels.forEach(p => p.style.display = (parseInt(p.dataset.step,10) === cur ? '' : 'none'));
        chips.forEach(c => {
          const k = parseInt(c.dataset.step,10);
          const num = c.querySelector('.wz-num');
          const col = c.dataset.col;
          if (k < cur) {
            c.style.color = 'var(--gray-800)'; c.style.borderColor = 'var(--gray-300)';
            num.style.background = col; num.style.color = '#fff';
          } else if (k === cur) {
            c.style.color = col; c.style.borderColor = col;
            num.style.background = col; num.style.color = '#fff';
          } else {
            c.style.color = 'var(--gray-500)'; c.style.borderColor = 'var(--gray-200)';
            num.style.background = 'var(--gray-200)'; num.style.color = 'var(--gray-600)';
          }
        });
        btnPrev.disabled = (cur === 1);
        btnNext.style.display = (cur === steps ? 'none' : '');
        btnSub.style.display  = (cur === steps ? '' : 'none');
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }

      btnPrev.addEventListener('click', () => show(cur - 1));
      btnNext.addEventListener('click', () => {
        // Valideer basisstap vóór vooruit vanuit stap 1
        if (cur === 1) {
          const name = form.querySelector('input[name="name"]');
          if (!name.value.trim()) { name.reportValidity(); return; }
        }
        show(cur + 1);
      });
      chips.forEach(c => c.addEventListener('click', () => {
        const k = parseInt(c.dataset.step,10);
        if (k < cur) show(k); // alleen terug via chip
      }));

      window.wzCheckAll = function (code, on) {
        document.querySelectorAll('[data-tplgroup="' + code + '"] input[type=checkbox]')
          .forEach(cb => cb.checked = on);
      };

      show(1);
    })();
  </script>

<?php };

require __DIR__ . '/../templates/layout.php';
