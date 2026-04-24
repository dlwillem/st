<?php
/**
 * Repository-beheer per hoofdcategorie.
 *  - FUNC → Applicatieservices (platte templates, gekoppeld aan een applicatiesoort)
 *  - NFR  → Domeinen (platte subcategorie-templates)
 *  - VEND → Thema's leverancier (platte subcategorie-templates)
 *  - LIC  → Thema's licenties (platte subcategorie-templates)
 *  - SUP  → Thema's support (platte subcategorie-templates)
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/applicatiesoorten.php';
require_once __DIR__ . '/../includes/requirements.php';
require_login();
require_can('repository.edit');

$tabs = [
    'FUNC' => ['title' => 'Applicatieservices',   'singular' => 'applicatieservice'],
    'NFR'  => ['title' => 'Domeinen',             'singular' => 'domein'],
    'VEND' => ['title' => 'Thema\'s leverancier', 'singular' => 'thema'],
    'LIC'  => ['title' => 'Thema\'s licenties',   'singular' => 'thema'],
    'SUP'  => ['title' => 'Thema\'s support',     'singular' => 'thema'],
];
$catIds = [];
foreach ($tabs as $code => $_) {
    $catIds[$code] = (int)db_value('SELECT id FROM categorieen WHERE code = :c', [':c' => $code]);
}
$funcId = $catIds['FUNC'];

$activeTab = input_str('tab');
if (!isset($tabs[$activeTab])) $activeTab = 'FUNC';
$filterApp = (int)input('app', 0); // alleen FUNC-tab

// ─── POST ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = input_str('action');
    $redirectTab = input_str('tab') ?: $activeTab;
    $redirectApp = (int)input('filter_app', 0);
    try {
        switch ($action) {
            case 'app_create':
                applicatiesoort_create(
                    input_str('label'),
                    input_str('description')
                );
                flash_set('success', 'Applicatiesoort toegevoegd.');
                break;

            case 'tpl_create':
                $catId = (int)input('categorie_id');
                $appId = (int)input('applicatiesoort_id');
                $name  = input_str('name');
                if ($name === '') throw new RuntimeException('Naam is verplicht.');
                if (!$catId)      throw new RuntimeException('Hoofdcategorie ontbreekt.');
                db_insert('subcategorie_templates', [
                    'categorie_id'       => $catId,
                    'applicatiesoort_id' => $appId ?: null,
                    'name'               => $name,
                    'sort_order'         => 0,
                ]);
                audit_log('template_created', 'subcat_template', null, $name);
                flash_set('success', 'Toegevoegd.');
                break;

            case 'tpl_update':
                $id   = (int)input('id');
                $name = input_str('name');
                if ($name === '') throw new RuntimeException('Naam is verplicht.');
                db_update('subcategorie_templates', [
                    'name' => $name,
                ], 'id = :id', [':id' => $id]);
                audit_log('template_updated', 'subcat_template', $id, $name);
                flash_set('success', 'Bijgewerkt.');
                break;

            case 'tpl_delete':
                $id = (int)input('id');
                db_exec('DELETE FROM subcategorie_templates WHERE id = :id', [':id' => $id]);
                audit_log('template_deleted', 'subcat_template', $id, '');
                flash_set('success', 'Verwijderd.');
                break;

            case 'autolink':
                $a = applicatiesoorten_autolink_templates();
                $b = applicatiesoorten_autolink_existing();
                flash_set('success', "Auto-koppeling: $a templates, $b traject-subcats.");
                break;

            default:
                throw new RuntimeException('Onbekende actie.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    $qs = 'tab=' . urlencode($redirectTab);
    if ($redirectApp) $qs .= '&app=' . $redirectApp;
    redirect('pages/repository.php?' . $qs);
}

// ─── Data laden ──────────────────────────────────────────────────────────────
$activeCatId = $catIds[$activeTab];
$applicatiesoorten = db_all('SELECT id, label, description FROM applicatiesoorten ORDER BY sort_order, label');

if ($activeTab === 'FUNC') {
    $sql = "SELECT t.id, t.name, t.applicatiesoort_id, a.label AS app_label, a.description AS app_description
              FROM subcategorie_templates t
              LEFT JOIN applicatiesoorten a ON a.id = t.applicatiesoort_id
             WHERE t.categorie_id = :c";
    $params = [':c' => $funcId];
    if ($filterApp > 0) {
        $sql .= ' AND t.applicatiesoort_id = :a';
        $params[':a'] = $filterApp;
    } elseif ($filterApp === -1) {
        $sql .= ' AND t.applicatiesoort_id IS NULL';
    }
    $sql .= ' ORDER BY a.sort_order, a.label, t.sort_order, t.name';
    $rows = db_all($sql, $params);
} else {
    $rows = db_all(
        "SELECT id, name FROM subcategorie_templates
          WHERE categorie_id = :c
          ORDER BY sort_order, name",
        [':c' => $activeCatId]
    );
}

$pageTitle  = 'Structuur stamdata';
$currentNav = 'repository';

$bodyRenderer = function () use ($tabs, $activeTab, $activeCatId, $rows, $applicatiesoorten, $filterApp, $funcId) {
    ?>
  <div class="page-header">
    <div>
      <h1>Structuur stamdata</h1>
      <p>Standaard subcategorieën per hoofdcategorie. Bij het aanmaken van een traject kies je welke van toepassing zijn.</p>
    </div>
    <div class="actions">
      <?php if ($activeTab === 'FUNC'): ?>
        <form method="post" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="autolink">
          <input type="hidden" name="tab" value="FUNC">
          <button type="submit" class="btn ghost" title="Vul lege applicatiesoort_id in o.b.v. naampatroon">
            <?= icon('refresh', 14) ?> Auto-koppelen
          </button>
        </form>
        <button type="button" class="btn"
                onclick="document.getElementById('new-app-modal').style.display='flex'">
          <?= icon('plus', 14) ?> Nieuwe applicatiesoort
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tabs -->
  <div class="tabs" style="display:flex;gap:4px;border-bottom:1px solid var(--gray-200);margin-bottom:16px;flex-wrap:wrap;">
    <?php foreach ($tabs as $code => $m):
      $active = ($code === $activeTab);
      $style  = requirement_cat_style($code);
      $col    = 'var(--' . $style['color'] . '-600)';
    ?>
      <a href="<?= h(APP_BASE_URL) ?>/pages/repository.php?tab=<?= h($code) ?>"
         class="tab<?= $active ? ' active' : '' ?>"
         style="padding:8px 14px;border:1px solid <?= $active ? 'var(--gray-200)' : 'transparent' ?>;border-top:<?= $active ? '3px solid ' . h($col) : '1px solid transparent' ?>;border-bottom:<?= $active ? '1px solid #fff' : 'none' ?>;border-radius:6px 6px 0 0;margin-bottom:-1px;background:<?= $active ? '#fff' : 'transparent' ?>;color:<?= $active ? h($col) : 'var(--gray-600)' ?>;text-decoration:none;font-weight:<?= $active ? '600' : '500' ?>;font-size:0.875rem;display:inline-flex;align-items:center;gap:6px;">
        <span style="color:<?= h($col) ?>;display:inline-flex;"><?= icon($style['icon'], 14) ?></span>
        <?= h($code) ?> — <?= h($m['title']) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="card" style="padding:0;">
    <div style="padding:12px 16px;border-bottom:1px solid var(--gray-200);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
      <div>
        <strong><?= h($tabs[$activeTab]['title']) ?></strong>
        <span class="muted small">(<?= count($rows) ?>)</span>
      </div>
      <?php if ($activeTab === 'FUNC'): ?>
        <form method="get" class="row-sm" style="gap:6px;align-items:center;margin:0;">
          <input type="hidden" name="tab" value="FUNC">
          <label class="muted small" for="filter-app">Filter applicatiesoort:</label>
          <select id="filter-app" name="app" class="input" style="margin-top:0;width:auto;min-width:220px;" onchange="this.form.submit()">
            <option value="0">— alles —</option>
            <option value="-1" <?= $filterApp === -1 ? 'selected' : '' ?>>(zonder applicatiesoort)</option>
            <?php foreach ($applicatiesoorten as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= $filterApp === (int)$a['id'] ? 'selected' : '' ?>>
                <?= h($a['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>
    </div>

    <div style="padding:8px 16px 16px;">
      <table class="table" style="font-size:0.875rem;">
        <thead>
          <tr>
            <th>Naam</th>
            <?php if ($activeTab === 'FUNC'): ?>
              <th style="width:280px;">Applicatiesoort</th>
            <?php endif; ?>
            <th style="width:140px;" class="right"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $t): ?>
            <tr>
              <form method="post" style="display:contents;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="tpl_update">
                <input type="hidden" name="tab" value="<?= h($activeTab) ?>">
                <?php if ($activeTab === 'FUNC' && $filterApp): ?>
                  <input type="hidden" name="filter_app" value="<?= (int)$filterApp ?>">
                <?php endif; ?>
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <td><input type="text" class="input" name="name" value="<?= h($t['name']) ?>" required maxlength="200" style="margin-top:0;"></td>
                <?php if ($activeTab === 'FUNC'): ?>
                  <td>
                    <input type="text" class="input" readonly
                           value="<?= h($t['app_label'] ?? '—') ?>"
                           title="<?= h($t['app_description'] ?? '') ?>"
                           style="margin-top:0;background:var(--gray-50);cursor:help;">
                  </td>
                <?php endif; ?>
                <td class="right">
                  <button type="submit" class="btn sm ghost"><?= icon('check', 12) ?></button>
                  <button type="submit" name="action" value="tpl_delete" class="btn sm ghost"
                          style="color:var(--red-700);"
                          onclick="return confirm('<?= h(ucfirst($tabs[$activeTab]['singular'])) ?> verwijderen?');">
                    <?= icon('trash', 12) ?>
                  </button>
                </td>
              </form>
            </tr>
          <?php endforeach; ?>
          <tr>
            <form method="post" style="display:contents;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="tpl_create">
              <input type="hidden" name="tab" value="<?= h($activeTab) ?>">
              <?php if ($activeTab === 'FUNC' && $filterApp): ?>
                <input type="hidden" name="filter_app" value="<?= (int)$filterApp ?>">
              <?php endif; ?>
              <input type="hidden" name="categorie_id" value="<?= (int)$activeCatId ?>">
              <td><input type="text" class="input" name="name" placeholder="Nieuwe <?= h($tabs[$activeTab]['singular']) ?>…" maxlength="200" style="margin-top:0;"></td>
              <?php if ($activeTab === 'FUNC'): ?>
                <td>
                  <select name="applicatiesoort_id" class="input" required style="margin-top:0;">
                    <option value="">— kies applicatiesoort —</option>
                    <?php foreach ($applicatiesoorten as $a): ?>
                      <option value="<?= (int)$a['id'] ?>"
                              <?= $filterApp > 0 && (int)$a['id'] === $filterApp ? 'selected' : '' ?>
                              title="<?= h((string)$a['description']) ?>">
                        <?= h($a['label']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
              <?php endif; ?>
              <td class="right"><button type="submit" class="btn sm"><?= icon('plus', 12) ?> Toevoegen</button></td>
            </form>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Nieuwe applicatiesoort modal (alleen FUNC-tab) -->
  <?php if ($activeTab === 'FUNC'): ?>
  <div id="new-app-modal" class="modal-backdrop" style="display:none;"
       onclick="if(event.target===this)this.style.display='none'">
    <div class="modal">
      <div class="modal-header">
        <h2>Nieuwe applicatiesoort</h2>
        <button type="button" class="btn-icon" onclick="document.getElementById('new-app-modal').style.display='none'">
          <?= icon('x', 16) ?>
        </button>
      </div>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="app_create">
        <input type="hidden" name="tab" value="FUNC">
        <div class="modal-body">
          <label class="field">Label
            <input type="text" class="input" name="label" required maxlength="200" placeholder="bijv. L-99 ERP-light" autofocus>
          </label>
          <label class="field">Beschrijving
            <textarea class="input" name="description" rows="3" maxlength="2000"></textarea>
          </label>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn ghost" onclick="document.getElementById('new-app-modal').style.display='none'">
            Annuleren
          </button>
          <button type="submit" class="btn">Aanmaken</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

<?php };

require __DIR__ . '/../templates/layout.php';
