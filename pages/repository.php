<?php
/**
 * Structuur stamdata — alle stamdata voor de wizard en requirements:
 *  - APP  → Applicatiesoorten (FUNC-groepering, geen eigen hoofdcategorie-record)
 *  - FUNC → Applicatieservices (per applicatiesoort, collapse-groepering)
 *  - NFR  → Domeinen (platte thema-templates)
 *  - VEND → Thema's leverancier
 *  - IMPL → Thema's implementatie
 *  - SUP  → Thema's support
 *  - LIC  → Thema's licenties
 *
 * Tab-volgorde is bewust APP → FUNC → NFR → VEND → IMPL → SUP → LIC.
 * Stijl is afgestemd op de design-handoff: Nunito Sans, design tokens
 * binnen .repo-screen scope, DetailPopup-modals voor create/edit.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/applicatiesoorten.php';
require_once __DIR__ . '/../includes/requirements.php';
require_login();
require_can('repository.edit');

// ── Tab-config ───────────────────────────────────────────────────────────────
// Volgorde: APP eerst (groepering), dan de zes hoofdcategorieën in vaste volgorde.
$tabs = [
    'APP'  => ['title' => 'App soorten',          'singular' => 'app soort',         'pill' => 'cyan'],
    'FUNC' => ['title' => 'Applicatieservices',   'singular' => 'applicatieservice', 'pill' => 'blue'],
    'NFR'  => ['title' => 'Domeinen',             'singular' => 'domein',            'pill' => 'amber'],
    'VEND' => ['title' => "Thema's leverancier",  'singular' => 'thema',             'pill' => 'green'],
    'IMPL' => ['title' => "Thema's implementatie",'singular' => 'thema',             'pill' => 'cyan2'],
    'SUP'  => ['title' => "Thema's support",      'singular' => 'thema',             'pill' => 'violet'],
    'LIC'  => ['title' => "Thema's licenties",    'singular' => 'thema',             'pill' => 'red'],
];

$catIds = [];
foreach ($tabs as $code => $_) {
    if ($code === 'APP') continue;
    $catIds[$code] = (int)db_value('SELECT id FROM categorieen WHERE code = :c', [':c' => $code]);
}

$activeTab = input_str('tab');
if (!isset($tabs[$activeTab])) $activeTab = 'APP';
$activeCatId = $catIds[$activeTab] ?? 0;

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = input_str('action');
    $redirectTab = input_str('tab') ?: $activeTab;
    try {
        switch ($action) {
            case 'app_create':
                applicatiesoort_create(input_str('name'), input_str('description'), input_str('bron'));
                flash_set('success', 'App soort toegevoegd.');
                break;

            case 'app_update':
                applicatiesoort_update((int)input_str('id'), input_str('name'), input_str('description'), input_str('bron'));
                flash_set('success', 'App soort bijgewerkt.');
                break;

            case 'app_delete':
                applicatiesoort_delete((int)input_str('id'));
                flash_set('success', 'App soort verwijderd.');
                break;

            case 'tpl_create': {
                $catId = (int)input('categorie_id');
                $appId = (int)input('applicatiesoort_id');
                $name  = input_str('name');
                $bron  = input_str('bron');
                $desc  = input_str('description');
                if ($name === '') throw new RuntimeException('Naam is verplicht.');
                if (!$catId)      throw new RuntimeException('Hoofdcategorie ontbreekt.');
                db_insert('subcategorie_templates', [
                    'categorie_id'       => $catId,
                    'applicatiesoort_id' => $appId ?: null,
                    'name'               => $name,
                    'bron'               => $bron !== '' ? $bron : null,
                    'description'        => $desc !== '' ? $desc : null,
                    'sort_order'         => 0,
                ]);
                audit_log('template_created', 'subcat_template', null, $name);
                flash_set('success', 'Toegevoegd.');
                break;
            }

            case 'tpl_update': {
                $id    = (int)input('id');
                $name  = input_str('name');
                $bron  = input_str('bron');
                $desc  = input_str('description');
                if ($name === '') throw new RuntimeException('Naam is verplicht.');
                $patch = [
                    'name'        => $name,
                    'bron'        => $bron !== '' ? $bron : null,
                    'description' => $desc !== '' ? $desc : null,
                ];
                db_update('subcategorie_templates', $patch, 'id = :id', [':id' => $id]);
                audit_log('template_updated', 'subcat_template', $id, $name);
                flash_set('success', 'Bijgewerkt.');
                break;
            }

            case 'tpl_delete': {
                $id    = (int)input('id');
                $count = (int)db_value(
                    'SELECT COUNT(*) FROM subcategorieen s
                       JOIN subcategorie_templates t
                         ON t.id = :id
                      WHERE s.categorie_id = t.categorie_id
                        AND s.name = t.name',
                    [':id' => $id]
                );
                if ($count > 0) {
                    throw new RuntimeException(
                        'Kan niet verwijderen: nog gekoppeld aan ' . $count . ' traject-subcategorie(ën). '
                        . 'Verwijder eerst die koppelingen of kies een andere naam.'
                    );
                }
                db_exec('DELETE FROM subcategorie_templates WHERE id = :id', [':id' => $id]);
                audit_log('template_deleted', 'subcat_template', $id, '');
                flash_set('success', 'Verwijderd.');
                break;
            }

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
    redirect('pages/repository.php?tab=' . urlencode($redirectTab));
}

// ── Data laden ───────────────────────────────────────────────────────────────
$applicatiesoorten = db_all('SELECT id, name, description, bron FROM applicatiesoorten ORDER BY name');

$apps = [];     // APP-tab
$funcRows = []; // FUNC-tab (gegroepeerd per applicatiesoort)
$themaRows = []; // NFR/VEND/IMPL/SUP/LIC-tabs (platte rijen)

if ($activeTab === 'APP') {
    $apps = applicatiesoorten_with_usage();
} elseif ($activeTab === 'FUNC') {
    // Tel "in trajecten" via gelijke (categorie_id, name)-koppeling met subcategorieen.
    $funcId = $catIds['FUNC'];
    $funcRows = db_all(
        "SELECT t.id, t.name, t.bron, t.description, t.applicatiesoort_id,
                a.name AS app_name, a.description AS app_description,
                (SELECT COUNT(DISTINCT s.traject_id)
                   FROM subcategorieen s
                  WHERE s.categorie_id = t.categorie_id
                    AND s.name = t.name) AS in_trajecten
           FROM subcategorie_templates t
           LEFT JOIN applicatiesoorten a ON a.id = t.applicatiesoort_id
          WHERE t.categorie_id = :c
          ORDER BY a.name IS NULL, a.name, t.name",
        [':c' => $funcId]
    );
} else {
    $themaRows = db_all(
        "SELECT t.id, t.name, t.bron, t.description,
                (SELECT COUNT(DISTINCT s.traject_id)
                   FROM subcategorieen s
                  WHERE s.categorie_id = t.categorie_id
                    AND s.name = t.name) AS in_trajecten
           FROM subcategorie_templates t
          WHERE t.categorie_id = :c
          ORDER BY t.name",
        [':c' => $activeCatId]
    );
}

$pageTitle  = 'Structuur stamdata';
$currentNav = 'repository';

$bodyRenderer = function () use ($tabs, $activeTab, $activeCatId, $apps, $funcRows, $themaRows, $applicatiesoorten) {
?>
<style>
  /* Lokale design-tokens — afkomstig uit het stamdata-handoff-prototype.
     Gescoped onder .repo-screen zodat dit experimenteel kan landen
     zonder de rest van de app te raken; later vertalen we dit naar globaal. */
  @import url('https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;500;600;700;800&display=swap');

  .repo-screen {
    --pri:    #0891b2;
    --pri-h:  #0e7490;
    --grn:    #10b981;
    --txt:    #0d2d3a;
    --muted:  #6b7280;
    --border: #e2e6ea;
    --bg:     #f4f6f8;
    --card:   #ffffff;
    --r:      10px;
    --r-sm:   6px;
    --sh:     0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.04);
    --sh-h:   0 6px 20px rgba(0,0,0,.1), 0 16px 40px rgba(0,0,0,.07);
    font-family: 'Nunito Sans', system-ui, sans-serif;
    color: var(--txt);
  }
  .repo-screen h1, .repo-screen h2, .repo-screen p { font-family: inherit; }

  /* Cpills (tab-categorie-kleuren) */
  .repo-screen .cpill { padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase; }
  .repo-screen .cpill.cyan   { background:rgba(8,145,178,.10);  color:#0891b2; }
  .repo-screen .cpill.blue   { background:rgba(59,130,246,.10); color:#2563eb; }
  .repo-screen .cpill.amber  { background:rgba(245,158,11,.10); color:#b45309; }
  .repo-screen .cpill.green  { background:rgba(16,185,129,.10); color:#059669; }
  .repo-screen .cpill.cyan2  { background:rgba(6,182,212,.10);  color:#0e7490; }
  .repo-screen .cpill.violet { background:rgba(139,92,246,.10); color:#7c3aed; }
  .repo-screen .cpill.red    { background:rgba(239,68,68,.10);  color:#dc2626; }

  /* Card + tabs binnenin */
  .repo-card { background:var(--card);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--sh);margin-bottom:18px; }

  .repo-tabs { display:flex;gap:2px;padding:6px 10px 0;border-bottom:1px solid var(--border);background:#fff;flex-wrap:wrap; }
  .repo-tabs a { display:inline-flex;align-items:center;gap:6px;padding:7px 10px;margin-bottom:-1px;font-size:12px;font-weight:600;text-decoration:none;color:var(--muted);border-bottom:2px solid transparent;border-radius:6px 6px 0 0;transition:color .15s,border-color .15s,background .15s; }
  .repo-tabs a:hover { color:var(--txt);background:rgba(0,0,0,.025); }
  .repo-tabs a.active { color:var(--pri);border-bottom-color:var(--pri);background:rgba(8,145,178,.05); }

  .repo-card-head { padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--border);flex-wrap:wrap; }
  .repo-card-head .sub { color:var(--muted);font-size:12.5px;font-weight:500; }
  .repo-card-head .count { color:var(--muted);font-size:13px;font-weight:600; }
  .repo-card-body { padding:0; }

  .dt { width:100%;border-collapse:collapse;font-size:13.5px; }
  .dt th { font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);text-align:left;padding:10px 18px;background:#fafbfc;border-bottom:1px solid var(--border); }
  .dt td { padding:11px 18px;border-bottom:1px solid var(--border);vertical-align:middle; }
  .dt tr:last-child td { border-bottom:0; }
  .dt tr:hover td { background:#f9fafb; }
  .dt .name { font-weight:600;color:var(--txt); }
  .dt .muted { color:var(--muted); }
  .dt .empty { padding:30px 18px;text-align:center;color:var(--muted);font-style:italic; }

  /* Count-badges (lichte rounded vierkanten) */
  .cb { display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:24px;padding:0 8px;border-radius:6px;font-size:12px;font-weight:700; }
  .cb.blue { background:rgba(8,145,178,.10);color:var(--pri); }
  .cb.green { background:rgba(16,185,129,.10);color:var(--grn); }
  .cb.zero { background:#f3f4f6;color:#9ca3af; }

  /* InfoTooltip (i-icoontje na een naam) */
  .itip { position:relative;display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:rgba(8,145,178,.12);color:var(--pri);font-size:10px;font-weight:700;font-style:italic;font-family:'Times New Roman',serif;cursor:help;margin-left:6px;vertical-align:middle;line-height:1; }
  .itip::before { content:'i'; }
  .itip:hover .itip-pop, .itip:focus .itip-pop { opacity:1;visibility:visible;transform:translate(-50%,0); }
  .itip-pop { position:absolute;bottom:calc(100% + 8px);left:50%;transform:translate(-50%,4px);background:#0d2d3a;color:#fff;font-style:normal;font-family:'Nunito Sans',system-ui,sans-serif;font-size:12px;font-weight:500;line-height:1.45;padding:8px 12px;border-radius:6px;width:260px;text-align:left;opacity:0;visibility:hidden;transition:opacity .15s,transform .15s,visibility .15s;pointer-events:none;z-index:10;box-shadow:0 6px 20px rgba(0,0,0,.18); }
  .itip-pop::after { content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);border:5px solid transparent;border-top-color:#0d2d3a; }

  /* Knoppen */
  .rb { display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:var(--r-sm);font-size:13px;font-weight:600;border:1px solid transparent;cursor:pointer;text-decoration:none;background:transparent;color:var(--txt);transition:background .15s,border-color .15s,color .15s; }
  .rb:hover { background:rgba(0,0,0,.04); }
  .rb.primary { background:var(--pri);color:#fff;border-color:var(--pri); }
  .rb.primary:hover { background:var(--pri-h);border-color:var(--pri-h); }
  .rb.ghost { border-color:var(--border); }
  .rb.danger { color:#dc2626; }
  .rb.danger:hover { background:rgba(220,38,38,.08); }
  .rb.sm { padding:5px 9px;font-size:12px; }
  .rb[disabled] { opacity:.4;cursor:not-allowed; }

  /* Search */
  .repo-search { display:flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--border);border-radius:var(--r-sm);padding:6px 10px;font-size:13px;min-width:240px; }
  .repo-search input { border:0;outline:0;flex:1;font:inherit;color:var(--txt);background:transparent; }
  .repo-search svg { color:var(--muted); }

  /* FUNC group-collapse */
  .fgroup { border-top:1px solid var(--border); }
  .fgroup:first-child { border-top:0; }
  .fgroup-head { display:flex;align-items:center;gap:10px;padding:12px 18px;background:#fafbfc;cursor:pointer;user-select:none; }
  .fgroup-head:hover { background:#f4f6f8; }
  .fgroup-head .chev { transition:transform .2s;color:var(--muted); }
  .fgroup-head.collapsed .chev { transform:rotate(-90deg); }
  .fgroup-head .gname { font-weight:700;font-size:14px; }
  .fgroup-head .gcount { color:var(--muted);font-size:12.5px;font-weight:500; }
  .fgroup-body { display:block; }
  .fgroup-head.collapsed + .fgroup-body { display:none; }
  .fgroup-body td.indent { padding-left:50px; }

  /* Modal */
  .rmodal-bg { position:fixed;inset:0;background:rgba(13,45,58,.45);display:none;align-items:center;justify-content:center;z-index:1000;padding:20px; }
  .rmodal-bg.open { display:flex; }
  .rmodal { background:#fff;border-radius:var(--r);box-shadow:var(--sh-h);width:520px;max-width:100%;padding:28px;font-family:'Nunito Sans',system-ui,sans-serif;color:var(--txt); }
  .rmodal h3 { margin:0 0 18px;font-size:18px;font-weight:700; }
  .rmodal .fl { display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:14px 0 6px; }
  .rmodal .fl:first-of-type { margin-top:0; }
  .rmodal .fi, .rmodal .fta { width:100%;border:1px solid var(--border);border-radius:var(--r-sm);padding:9px 12px;font:inherit;color:var(--txt);background:#fff; }
  .rmodal .fi:focus, .rmodal .fta:focus { outline:0;border-color:var(--pri); }
  .rmodal .fta { resize:vertical;min-height:80px; }
  .rmodal .ro { background:#f9fafb;color:var(--muted); }
  .rmodal .actions { display:flex;justify-content:flex-end;gap:10px;margin-top:22px; }

  /* Page header */
  .repo-screen .page-header { margin-bottom:0; }
</style>

<div class="repo-screen">
  <div class="page-header">
    <div>
      <h1>Structuur stamdata</h1>
      <p class="muted small">Centrale stamdata voor de wizard en requirements. Wijzigingen hier propageren naar nieuw aangemaakte trajecten.</p>
    </div>
  </div>

  <div class="repo-card">
    <!-- Tabs binnen het witte blok -->
    <nav class="repo-tabs">
      <?php foreach ($tabs as $code => $m): $active = ($code === $activeTab); ?>
        <a href="<?= h(APP_BASE_URL) ?>/pages/repository.php?tab=<?= h($code) ?>" class="<?= $active ? 'active' : '' ?>">
          <span class="cpill <?= h($m['pill']) ?>"><?= h($code) ?></span>
          — <?= h($m['title']) ?>
        </a>
      <?php endforeach; ?>
    </nav>

  <?php if ($activeTab === 'APP'): ?>
    <!-- ─── APP-tab ───────────────────────────────────────────────── -->
      <div class="repo-card-head">
        <label class="repo-search">
          <?= icon('search', 14) ?>
          <input type="search" placeholder="Zoek app soort…" oninput="repoFilter('app', this.value)">
        </label>
        <div style="display:flex;align-items:center;gap:14px;">
          <span class="count"><?= count($apps) ?> soort<?= count($apps) === 1 ? '' : 'en' ?></span>
          <button type="button" class="rb primary" onclick="appOpen()"><?= icon('plus', 14) ?> Nieuwe app soort</button>
        </div>
      </div>
      <div class="repo-card-body">
        <table class="dt" id="app-table">
          <colgroup>
            <col><col><col style="width:140px;"><col style="width:140px;"><col style="width:160px;">
          </colgroup>
          <thead>
            <tr><th>Naam</th><th>Bron</th><th>App-services</th><th>In trajecten</th><th></th></tr>
          </thead>
          <tbody>
            <?php if (!$apps): ?>
              <tr><td colspan="5" class="empty">Nog geen app soorten — voeg er één toe.</td></tr>
            <?php else: foreach ($apps as $a): $busy = ((int)$a['templates'] + (int)$a['instances']) > 0; ?>
              <tr data-search="<?= h(mb_strtolower($a['name'] . ' ' . ($a['bron'] ?? ''))) ?>">
                <td>
                  <span class="name"><?= h($a['name']) ?></span>
                  <?php if (!empty($a['description'])): ?>
                    <span class="itip" tabindex="0" aria-label="Beschrijving"><span class="itip-pop"><?= h($a['description']) ?></span></span>
                  <?php endif; ?>
                </td>
                <td class="muted"><?= h((string)($a['bron'] ?? '')) ?: '<span style="color:#cbd5e1;">—</span>' ?></td>
                <td><span class="cb <?= ((int)$a['templates']) ? 'blue' : 'zero' ?>"><?= (int)$a['templates'] ?></span></td>
                <td><span class="cb <?= ((int)$a['instances']) ? 'green' : 'zero' ?>"><?= (int)$a['instances'] ?></span></td>
                <td style="text-align:right;white-space:nowrap;">
                  <button type="button" class="rb sm ghost"
                          data-id="<?= (int)$a['id'] ?>"
                          data-name="<?= h($a['name']) ?>"
                          data-desc="<?= h((string)($a['description'] ?? '')) ?>"
                          data-bron="<?= h((string)($a['bron'] ?? '')) ?>"
                          onclick="appEdit(this)"><?= icon('edit', 12) ?> Bewerken</button>
                  <?php if ($busy): ?>
                    <button type="button" class="rb sm" disabled
                            title="Kan niet verwijderen: nog <?= (int)$a['templates'] ?> service(s) en <?= (int)$a['instances'] ?> traject-koppeling(en).">
                      <?= icon('trash', 12) ?>
                    </button>
                  <?php else: ?>
                    <form method="post" style="display:inline;"
                          onsubmit="return confirm('App soort &quot;<?= h(addslashes($a['name'])) ?>&quot; verwijderen?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="app_delete">
                      <input type="hidden" name="tab" value="APP">
                      <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                      <button type="submit" class="rb sm danger" title="Verwijderen"><?= icon('trash', 12) ?></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div><!-- /.repo-card -->

    <!-- App soort modal -->
    <div class="rmodal-bg" id="app-modal" onclick="if(event.target===this)appClose()">
      <div class="rmodal">
        <h3 id="app-title">Nieuwe app soort</h3>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="tab" value="APP">
          <input type="hidden" name="action" id="app-action" value="app_create">
          <input type="hidden" name="id" id="app-id" value="">
          <label class="fl">Naam</label>
          <input type="text" class="fi" name="name" id="app-name" required maxlength="200" autofocus>
          <label class="fl">Beschrijving <span style="text-transform:none;font-weight:500;color:var(--muted);">(optioneel)</span></label>
          <textarea class="fta" name="description" id="app-desc" rows="3" maxlength="1000"></textarea>
          <label class="fl">Bron <span style="text-transform:none;font-weight:500;color:var(--muted);">(optioneel)</span></label>
          <input type="text" class="fi" name="bron" id="app-bron" maxlength="190" placeholder="bijv. APQC PCF 4.0 / LeanIX">
          <div class="actions">
            <button type="button" class="rb ghost" onclick="appClose()">Annuleren</button>
            <button type="submit" class="rb primary" id="app-submit">Aanmaken</button>
          </div>
        </form>
      </div>
    </div>

  <?php elseif ($activeTab === 'FUNC'): ?>
    <!-- ─── FUNC-tab: applicatieservices, gegroepeerd per app soort ────── -->
      <div class="repo-card-head">
        <label class="repo-search">
          <?= icon('search', 14) ?>
          <input type="search" placeholder="Zoek op naam of bron…" oninput="repoFilter('func', this.value)">
        </label>
        <div style="display:flex;align-items:center;gap:14px;">
          <span class="count"><?= count($funcRows) ?> service<?= count($funcRows) === 1 ? '' : 's' ?></span>
          <form method="post" style="display:inline;"
                onsubmit="return confirm('Auto-koppel templates en traject-subcats aan app soorten op basis van naam-match?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="autolink">
            <input type="hidden" name="tab" value="FUNC">
            <button type="submit" class="rb sm ghost" title="Koppel templates en traject-subcats aan app soorten op basis van naam-match"><?= icon('refresh', 12) ?> Auto-koppel</button>
          </form>
          <button type="button" class="rb primary" onclick="svcOpen(0)"><?= icon('plus', 14) ?> Nieuwe service</button>
        </div>
      </div>
      <div class="repo-card-body">
        <?php
          // Groepeer rijen per applicatiesoort
          $byApp = [];
          foreach ($funcRows as $r) {
              $key = (int)$r['applicatiesoort_id'];
              $byApp[$key]['name'] = $r['app_name'] ?? '— zonder app soort —';
              $byApp[$key]['app_id'] = $key;
              $byApp[$key]['rows'][] = $r;
          }
          if (!$funcRows) {
              echo '<div class="empty" style="padding:30px 18px;text-align:center;color:var(--muted);font-style:italic;">Nog geen applicatieservices.</div>';
          } else {
            foreach ($byApp as $g):
        ?>
<?php
            // Pak app-beschrijving uit de eerste rij van de groep (alle rijen hebben dezelfde app)
            $gDesc = $g['rows'][0]['app_description'] ?? '';
        ?>
          <div class="fgroup" data-search-group="<?= h(mb_strtolower($g['name'])) ?>">
            <div class="fgroup-head" onclick="this.classList.toggle('collapsed')">
              <span class="chev"><?= icon('chevron-down', 14) ?></span>
              <span class="gname"><?= h($g['name']) ?></span>
              <?php if (!empty($gDesc)): ?>
                <span class="itip" tabindex="0" aria-label="Beschrijving" onclick="event.stopPropagation()"><span class="itip-pop"><?= h($gDesc) ?></span></span>
              <?php endif; ?>
              <span class="gcount">· <?= count($g['rows']) ?> service<?= count($g['rows']) === 1 ? '' : 's' ?></span>
              <span style="margin-left:auto;">
                <?php if ($g['app_id']): ?>
                  <button type="button" class="rb sm ghost" onclick="event.stopPropagation();svcOpen(<?= (int)$g['app_id'] ?>)">
                    <?= icon('plus', 12) ?> Toevoegen
                  </button>
                <?php endif; ?>
              </span>
            </div>
            <div class="fgroup-body">
              <table class="dt">
                <colgroup>
                  <col style="width:36px;"><col><col style="width:200px;"><col style="width:140px;"><col style="width:160px;">
                </colgroup>
                <thead>
                  <tr><th></th><th>Naam</th><th>Bron</th><th>In trajecten</th><th></th></tr>
                </thead>
                <tbody>
                  <?php foreach ($g['rows'] as $r): ?>
                    <tr data-search="<?= h(mb_strtolower($r['name'] . ' ' . ($r['bron'] ?? ''))) ?>">
                      <td></td>
                      <td>
                        <span class="name"><?= h($r['name']) ?></span>
                        <?php if (!empty($r['description'])): ?>
                          <span class="itip" tabindex="0" aria-label="Beschrijving"><span class="itip-pop"><?= h($r['description']) ?></span></span>
                        <?php endif; ?>
                      </td>
                      <td class="muted"><?= h((string)($r['bron'] ?? '')) ?: '<span style="color:#cbd5e1;">—</span>' ?></td>
                      <td><span class="cb <?= ((int)$r['in_trajecten']) ? 'green' : 'zero' ?>"><?= (int)$r['in_trajecten'] ?></span></td>
                      <td style="text-align:right;white-space:nowrap;">
                        <button type="button" class="rb sm ghost"
                                data-id="<?= (int)$r['id'] ?>"
                                data-name="<?= h($r['name']) ?>"
                                data-bron="<?= h((string)($r['bron'] ?? '')) ?>"
                                data-desc="<?= h((string)($r['description'] ?? '')) ?>"
                                data-app="<?= h($r['app_name'] ?? '— zonder app soort —') ?>"
                                onclick="svcEdit(this)"><?= icon('edit', 12) ?> Bewerken</button>
                        <form method="post" style="display:inline;"
                              onsubmit="return confirm('Service &quot;<?= h(addslashes($r['name'])) ?>&quot; verwijderen?');">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="tpl_delete">
                          <input type="hidden" name="tab" value="FUNC">
                          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                          <button type="submit" class="rb sm danger" title="Verwijderen"><?= icon('trash', 12) ?></button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endforeach; } ?>
      </div>
    </div>

    <!-- Service modal -->
    <div class="rmodal-bg" id="svc-modal" onclick="if(event.target===this)svcClose()">
      <div class="rmodal">
        <h3 id="svc-title">Nieuwe applicatieservice</h3>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="tab" value="FUNC">
          <input type="hidden" name="action" id="svc-action" value="tpl_create">
          <input type="hidden" name="id" id="svc-id" value="">
          <input type="hidden" name="categorie_id" value="<?= (int)$activeCatId ?>">
          <label class="fl">App soort</label>
          <select class="fi" name="applicatiesoort_id" id="svc-app" required>
            <option value="">— kies app soort —</option>
            <?php foreach ($applicatiesoorten as $a): ?>
              <option value="<?= (int)$a['id'] ?>"><?= h($a['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" class="fi ro" id="svc-app-readonly" readonly style="display:none;">
          <label class="fl">Naam</label>
          <input type="text" class="fi" name="name" id="svc-name" required maxlength="200">
          <label class="fl">Beschrijving <span style="text-transform:none;font-weight:500;color:var(--muted);">(optioneel)</span></label>
          <textarea class="fta" name="description" id="svc-desc" rows="3" maxlength="2000"></textarea>
          <label class="fl">Bron <span style="text-transform:none;font-weight:500;color:var(--muted);">(optioneel)</span></label>
          <input type="text" class="fi" name="bron" id="svc-bron" maxlength="190">
          <div class="actions">
            <button type="button" class="rb ghost" onclick="svcClose()">Annuleren</button>
            <button type="submit" class="rb primary" id="svc-submit">Aanmaken</button>
          </div>
        </form>
      </div>
    </div>

  <?php else: /* NFR / VEND / IMPL / SUP / LIC */ ?>
    <!-- ─── Thema-tab ─────────────────────────────────────────────── -->
      <div class="repo-card-head">
        <label class="repo-search">
          <?= icon('search', 14) ?>
          <input type="search" placeholder="Zoek op naam of bron…" oninput="repoFilter('thm', this.value)">
        </label>
        <div style="display:flex;align-items:center;gap:14px;">
          <span class="count"><?= count($themaRows) ?> <?= h($tabs[$activeTab]['singular']) ?><?= count($themaRows) === 1 ? '' : 's' ?></span>
          <button type="button" class="rb primary" onclick="thmOpen()"><?= icon('plus', 14) ?> Nieuw <?= h($tabs[$activeTab]['singular']) ?></button>
        </div>
      </div>
      <div class="repo-card-body">
        <table class="dt" id="thm-table">
          <colgroup><col><col style="width:200px;"><col style="width:140px;"><col style="width:140px;"></colgroup>
          <thead>
            <tr><th>Naam</th><th>Bron</th><th>In trajecten</th><th></th></tr>
          </thead>
          <tbody>
            <?php if (!$themaRows): ?>
              <tr><td colspan="4" class="empty">Nog geen <?= h($tabs[$activeTab]['singular']) ?>s — voeg er één toe.</td></tr>
            <?php else: foreach ($themaRows as $r): ?>
              <tr data-search="<?= h(mb_strtolower($r['name'] . ' ' . ($r['bron'] ?? ''))) ?>">
                <td>
                  <span class="name"><?= h($r['name']) ?></span>
                  <?php if (!empty($r['description'])): ?>
                    <span class="itip" tabindex="0" aria-label="Beschrijving"><span class="itip-pop"><?= h($r['description']) ?></span></span>
                  <?php endif; ?>
                </td>
                <td class="muted"><?= h((string)($r['bron'] ?? '')) ?: '<span style="color:#cbd5e1;">—</span>' ?></td>
                <td><span class="cb <?= ((int)$r['in_trajecten']) ? 'green' : 'zero' ?>"><?= (int)$r['in_trajecten'] ?></span></td>
                <td style="text-align:right;white-space:nowrap;">
                  <button type="button" class="rb sm ghost"
                          data-id="<?= (int)$r['id'] ?>"
                          data-name="<?= h($r['name']) ?>"
                          data-bron="<?= h((string)($r['bron'] ?? '')) ?>"
                          data-desc="<?= h((string)($r['description'] ?? '')) ?>"
                          onclick="thmEdit(this)"><?= icon('edit', 12) ?> Bewerken</button>
                  <form method="post" style="display:inline;"
                        onsubmit="return confirm('<?= h(ucfirst($tabs[$activeTab]['singular'])) ?> &quot;<?= h(addslashes($r['name'])) ?>&quot; verwijderen?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="tpl_delete">
                    <input type="hidden" name="tab" value="<?= h($activeTab) ?>">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="rb sm danger" title="Verwijderen"><?= icon('trash', 12) ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Thema modal -->
    <div class="rmodal-bg" id="thm-modal" onclick="if(event.target===this)thmClose()">
      <div class="rmodal">
        <h3 id="thm-title">Nieuw <?= h($tabs[$activeTab]['singular']) ?></h3>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="tab" value="<?= h($activeTab) ?>">
          <input type="hidden" name="action" id="thm-action" value="tpl_create">
          <input type="hidden" name="id" id="thm-id" value="">
          <input type="hidden" name="categorie_id" value="<?= (int)$activeCatId ?>">
          <label class="fl">Naam</label>
          <input type="text" class="fi" name="name" id="thm-name" required maxlength="200" autofocus>
          <label class="fl">Beschrijving <span style="text-transform:none;font-weight:500;color:var(--muted);">(optioneel)</span></label>
          <textarea class="fta" name="description" id="thm-desc" rows="3" maxlength="2000"></textarea>
          <label class="fl">Bron <span style="text-transform:none;font-weight:500;color:var(--muted);">(optioneel)</span></label>
          <input type="text" class="fi" name="bron" id="thm-bron" maxlength="190">
          <div class="actions">
            <button type="button" class="rb ghost" onclick="thmClose()">Annuleren</button>
            <button type="submit" class="rb primary" id="thm-submit">Aanmaken</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  // ── App-soort modal
  const appM = document.getElementById('app-modal');
  window.appOpen = function () {
    document.getElementById('app-title').textContent = 'Nieuwe app soort';
    document.getElementById('app-action').value = 'app_create';
    document.getElementById('app-id').value = '';
    document.getElementById('app-name').value = '';
    document.getElementById('app-desc').value = '';
    document.getElementById('app-bron').value = '';
    document.getElementById('app-submit').textContent = 'Aanmaken';
    appM && appM.classList.add('open');
  };
  window.appEdit = function (btn) {
    document.getElementById('app-title').textContent = 'App soort bewerken';
    document.getElementById('app-action').value = 'app_update';
    document.getElementById('app-id').value = btn.dataset.id;
    document.getElementById('app-name').value = btn.dataset.name;
    document.getElementById('app-desc').value = btn.dataset.desc;
    document.getElementById('app-bron').value = btn.dataset.bron;
    document.getElementById('app-submit').textContent = 'Opslaan';
    appM && appM.classList.add('open');
  };
  window.appClose = function () { appM && appM.classList.remove('open'); };

  // ── Service (FUNC) modal
  const svcM = document.getElementById('svc-modal');
  window.svcOpen = function (appId) {
    document.getElementById('svc-title').textContent = 'Nieuwe applicatieservice';
    document.getElementById('svc-action').value = 'tpl_create';
    document.getElementById('svc-id').value = '';
    document.getElementById('svc-name').value = '';
    document.getElementById('svc-desc').value = '';
    document.getElementById('svc-bron').value = '';
    const sel = document.getElementById('svc-app');
    sel.style.display = '';
    sel.disabled = false;
    sel.value = appId ? String(appId) : '';
    document.getElementById('svc-app-readonly').style.display = 'none';
    document.getElementById('svc-submit').textContent = 'Aanmaken';
    svcM && svcM.classList.add('open');
  };
  window.svcEdit = function (btn) {
    document.getElementById('svc-title').textContent = 'Service bewerken';
    document.getElementById('svc-action').value = 'tpl_update';
    document.getElementById('svc-id').value = btn.dataset.id;
    document.getElementById('svc-name').value = btn.dataset.name;
    document.getElementById('svc-desc').value = btn.dataset.desc || '';
    document.getElementById('svc-bron').value = btn.dataset.bron;
    // App soort is bij update niet aanpasbaar — toon readonly
    const sel = document.getElementById('svc-app');
    sel.style.display = 'none';
    sel.disabled = true;
    const ro = document.getElementById('svc-app-readonly');
    ro.value = btn.dataset.app || '';
    ro.style.display = '';
    document.getElementById('svc-submit').textContent = 'Opslaan';
    svcM && svcM.classList.add('open');
  };
  window.svcClose = function () { svcM && svcM.classList.remove('open'); };

  // ── Thema modal
  const thmM = document.getElementById('thm-modal');
  window.thmOpen = function () {
    if (!thmM) return;
    document.getElementById('thm-action').value = 'tpl_create';
    document.getElementById('thm-id').value = '';
    document.getElementById('thm-name').value = '';
    document.getElementById('thm-desc').value = '';
    document.getElementById('thm-bron').value = '';
    document.getElementById('thm-submit').textContent = 'Aanmaken';
    thmM.classList.add('open');
  };
  window.thmEdit = function (btn) {
    document.getElementById('thm-action').value = 'tpl_update';
    document.getElementById('thm-id').value = btn.dataset.id;
    document.getElementById('thm-name').value = btn.dataset.name;
    document.getElementById('thm-desc').value = btn.dataset.desc || '';
    document.getElementById('thm-bron').value = btn.dataset.bron;
    document.getElementById('thm-submit').textContent = 'Opslaan';
    thmM && thmM.classList.add('open');
  };
  window.thmClose = function () { thmM && thmM.classList.remove('open'); };

  // ── Esc sluit modals
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      [appM, svcM, thmM].forEach(m => m && m.classList.remove('open'));
    }
  });

  // ── Search-filter (klant-side, simpel substring-match)
  window.repoFilter = function (which, q) {
    q = (q || '').toLowerCase().trim();
    if (which === 'app' || which === 'thm') {
      const tb = document.querySelector(which === 'app' ? '#app-table tbody' : '#thm-table tbody');
      if (!tb) return;
      tb.querySelectorAll('tr[data-search]').forEach(tr => {
        tr.style.display = (!q || tr.dataset.search.includes(q)) ? '' : 'none';
      });
    } else if (which === 'func') {
      document.querySelectorAll('.fgroup').forEach(g => {
        const groupHit = !q || g.dataset.searchGroup.includes(q);
        let any = false;
        g.querySelectorAll('tr[data-search]').forEach(tr => {
          const hit = !q || tr.dataset.search.includes(q) || groupHit;
          tr.style.display = hit ? '' : 'none';
          if (hit) any = true;
        });
        g.style.display = (any || groupHit) ? '' : 'none';
        // Klap automatisch open bij zoektreffer
        if (q && any) g.querySelector('.fgroup-head').classList.remove('collapsed');
      });
    }
  };
})();
</script>
<?php };

require __DIR__ . '/../templates/layout.php';
