<?php
/**
 * Traject-detail: tabs voor Details / Structuur / Weging.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/trajecten.php';
require_once __DIR__ . '/../includes/requirements.php';
require_once __DIR__ . '/../includes/weights.php';
require_once __DIR__ . '/../includes/subcategorieen.php';
require_once __DIR__ . '/../includes/scoring.php';
require_once __DIR__ . '/../includes/demo_catalog.php';
require_once __DIR__ . '/../includes/traject_deelnemers.php';
require_once __DIR__ . '/../includes/leveranciers.php';
require_once __DIR__ . '/../includes/lev_answers.php';
require_once __DIR__ . '/../includes/users.php';
require_login();

$id = input_int('id');
if (!$id) redirect('pages/trajecten.php');

$traject = db_one('SELECT t.*, u.name AS created_by_name
                     FROM trajecten t
                     LEFT JOIN users u ON u.id = t.created_by
                    WHERE t.id = :id', [':id' => $id]);
if (!$traject) {
    flash_set('error', 'Traject niet gevonden.');
    redirect('pages/trajecten.php');
}

if (!can_view_traject((int)$id)) { http_response_code(403); exit('Onvoldoende rechten.'); }

$caps = [
    'traject'  => can('trajecten.edit'),      // archive, delete, details-update
    'reqs'     => can('requirements.edit'),   // structuur-tab CRUD
    'lev'      => can('leveranciers.edit'),   // leveranciers-tab CRUD + upload
    'collegas' => can('traject.collegas'),    // collega's koppelen
    'scoring'  => can('scoring.manage'),      // rondes openen/sluiten/verwijderen
    'enter'    => can('scoring.enter'),       // zelf score invoeren
    'weging'   => can('weging.edit'),
];
$canWeight = $caps['weging'];
// Achterwaarts compatibel: $canEdit blijft beschikbaar als "kan íéts bewerken".
$canEdit   = $caps['traject'] || $caps['reqs'] || $caps['lev'] || $caps['collegas'] || $caps['scoring'];

// ─── Tab state ────────────────────────────────────────────────────────────────
$tab  = input_str('tab');
if (!in_array($tab, ['details', 'collegas', 'structuur', 'scoring', 'weging', 'leveranciers'], true)) $tab = 'details';
// weging tab mag iedereen zien (transparantie); edit-controls worden zelf afgeschermd.

$stab = input_str('stab');
if (!in_array($stab, ['FUNC', 'NFR', 'VEND', 'IMPL', 'SUP', 'LIC', 'DEMO'], true)) $stab = 'FUNC';

// Scoring drill-in: ?leverancier=X&scope=Y
$drillLev   = (int)input_int('leverancier');
$drillScope = (string)input_str('scope');
if (!in_array($drillScope, RONDE_SCOPES, true)) $drillScope = '';

// ─── POST-acties ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $action      = input_str('action');
    // Per-action autorisatie: elke action-naam vereist een specifieke capability.
    $actionCaps = [
        'update'          => 'trajecten.edit',
        'archive'         => 'trajecten.edit',
        'activate'        => 'trajecten.edit',
        'delete'          => 'trajecten.edit',
        'select'          => 'traject.view',
        'subcat_add'      => 'trajecten.edit',
        'subcat_rename'   => 'trajecten.edit',
        'subcat_update'   => 'trajecten.edit',
        'subcat_delete'   => 'trajecten.edit',
        'save_weights'    => 'weging.edit',
        'reset_weights'   => 'weging.edit',
        'save_demo_weights' => 'weging.edit',
        'ronde_create'    => 'scoring.manage',
        'rondes_bulk'     => 'scoring.manage',
        'ronde_open'      => 'scoring.manage',
        'ronde_close'     => 'scoring.manage',
        'ronde_delete'    => 'scoring.manage',
        'invite_send_all' => 'scoring.manage',
        'invite_demo'     => 'scoring.manage',
        'collega_add'     => 'traject.collegas',
        'collega_update'  => 'traject.collegas',
        'collega_delete'  => 'traject.collegas',
        'invite_resend'   => 'traject.collegas',
        'invite_revoke'   => 'traject.collegas',
        'lev_create'      => 'leveranciers.edit',
        'lev_update'      => 'leveranciers.edit',
        'lev_set_status'  => 'leveranciers.edit',
        'lev_delete'      => 'leveranciers.edit',
    ];
    $requiredCap = $actionCaps[$action] ?? null;
    if ($requiredCap === null || !can($requiredCap)) {
        http_response_code(403); exit('Onvoldoende rechten.');
    }
    // Traject-scope: elke write-actie moet ook can_view_traject() passeren, zodat
    // een gescopete rol niet buiten zijn toegewezen trajecten kan muteren via POST.
    if (!can_view_traject((int)$id)) {
        http_response_code(403); exit('Onvoldoende rechten voor dit traject.');
    }
    $redirectTab = input_str('tab') ?: 'details';
    if (!in_array($redirectTab, ['details', 'collegas', 'structuur', 'scoring', 'weging', 'leveranciers'], true)) $redirectTab = 'details';
    $redirectStab = input_str('stab') ?: 'FUNC';
    if (!in_array($redirectStab, ['FUNC', 'NFR', 'VEND', 'IMPL', 'SUP', 'LIC', 'DEMO'], true)) $redirectStab = 'FUNC';
    $redirectLev   = input_int('leverancier');
    $redirectScope = input_str('scope');
    if (!in_array($redirectScope, RONDE_SCOPES, true)) $redirectScope = '';
    $qs = 'id=' . $id . '&tab=' . $redirectTab;
    if ($redirectTab === 'structuur') $qs .= '&stab=' . $redirectStab;
    if ($redirectTab === 'scoring' && $redirectLev && $redirectScope) {
        $qs .= '&leverancier=' . $redirectLev . '&scope=' . urlencode($redirectScope);
    }

    try {
        if ($action === 'update') {
            $data = [
                'name'        => input_str('name'),
                'description' => input_str('description') ?: null,
                'status'      => input_str('status'),
                'start_date'  => input_str('start_date') ?: null,
                'end_date'    => input_str('end_date')   ?: null,
            ];
            if ($data['name'] === '') {
                flash_set('error', 'Naam is verplicht.');
            } elseif (!in_array($data['status'], TRAJECT_STATUSES, true)) {
                flash_set('error', 'Ongeldige status.');
            } else {
                traject_update($id, $data);
                flash_set('success', 'Traject bijgewerkt.');
            }
        } elseif ($action === 'archive') {
            traject_set_status($id, 'gearchiveerd');
            flash_set('success', 'Traject gearchiveerd.');
        } elseif ($action === 'activate') {
            traject_set_status($id, 'actief');
            flash_set('success', 'Traject geactiveerd.');
        } elseif ($action === 'delete') {
            $normalize = function (string $s): string {
                $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
                return mb_strtolower(trim($s));
            };
            $typed    = $normalize(input_str('confirm_name'));
            $expected = $normalize((string)$traject['name']);
            if ($typed === '' || $typed !== $expected) {
                flash_set('error',
                    'Bevestigingsnaam komt niet overeen. '
                    . 'Ontvangen: "' . input_str('confirm_name') . '". '
                    . 'Verwacht: "' . $traject['name'] . '".');
                redirect('pages/traject_detail.php?' . $qs);
            }
            traject_delete($id);
            flash_set('success', 'Traject verwijderd.');
            redirect('pages/trajecten.php');
        // ─── Structuur ──────────────────────────────────────────────────────
        } elseif ($action === 'subcat_add') {
            $name  = input_str('name');
            $bron  = input_str('bron');
            $desc  = input_str('description');
            $catId = input_int('categorie_id');
            if ($name === '' || !$catId) throw new RuntimeException('Naam en hoofdcategorie zijn verplicht.');
            subcat_create($id, $catId, $name, $bron !== '' ? $bron : null, $desc !== '' ? $desc : null);
            flash_set('success', 'Toegevoegd: ' . $name);
        } elseif ($action === 'subcat_rename') {
            $sid  = input_int('sub_id', 0);
            $name = input_str('name');
            if (!$sid || $name === '') throw new RuntimeException('Ongeldige invoer.');
            subcat_rename($sid, $id, $name);
            flash_set('success', 'Bijgewerkt.');
        } elseif ($action === 'subcat_update') {
            $sid  = input_int('sub_id', 0);
            $name = input_str('name');
            $bron = input_str('bron');
            $desc = input_str('description');
            if (!$sid || $name === '') throw new RuntimeException('Ongeldige invoer.');
            subcat_update($sid, $id, [
                'name'        => $name,
                'bron'        => $bron,
                'description' => $desc,
            ]);
            flash_set('success', 'Bijgewerkt.');
        } elseif ($action === 'subcat_delete') {
            $sid = input_int('sub_id', 0);
            if (!$sid) throw new RuntimeException('Ongeldige invoer.');
            subcat_delete($sid, $id);
            flash_set('success', 'Verwijderd.');

        // ─── Weging ─────────────────────────────────────────────────────────
        } elseif ($action === 'save_weights') {
            $cats = $_POST['cat_weight'] ?? [];
            $subs = $_POST['sub_weight'] ?? [];
            if (!is_array($cats)) $cats = [];
            if (!is_array($subs)) $subs = [];
            $catsInt = []; foreach ($cats as $k => $v) $catsInt[(int)$k] = (float)$v;
            $subsInt = []; foreach ($subs as $k => $v) $subsInt[(int)$k] = (float)$v;
            weights_save($id, $catsInt, $subsInt);
            flash_set('success', 'Weging opgeslagen.');
        } elseif ($action === 'reset_weights') {
            db_exec('DELETE FROM weights WHERE traject_id = :t', [':t' => $id]);
            traject_init_weights($id);
            audit_log('weights_reset', 'traject', $id, '');
            flash_set('success', 'Weging hersteld naar gelijke verdeling.');
        } elseif ($action === 'save_demo_weights') {
            $demoPct = (float)input_str('demo_weight_pct');
            $demoPct = max(0, min(100, $demoPct));
            db_update('trajecten', ['demo_weight_pct' => $demoPct], 'id = :id', [':id' => $id]);
            audit_log('demo_weights_saved', 'traject', $id, 'demo_pct=' . $demoPct);
            flash_set('success', 'Demo-weging opgeslagen.');

        // ─── Scoring ────────────────────────────────────────────────────────
        } elseif ($action === 'ronde_create') {
            $lev   = input_int('leverancier');
            $scope = input_str('scope');
            ronde_upsert($id, $lev, $scope);
            flash_set('success', 'Ronde aangemaakt (concept).');
        } elseif ($action === 'rondes_bulk') {
            $combos = $_POST['combo'] ?? [];
            $created = 0;
            if (is_array($combos)) {
                db_transaction(function () use ($combos, $id, &$created) {
                    foreach ($combos as $key => $_) {
                        if (!preg_match('/^(\d+)_([A-Z]+)$/', (string)$key, $m)) continue;
                        $lev   = (int)$m[1];
                        $scope = $m[2];
                        if (!in_array($scope, RONDE_SCOPES, true)) continue;
                        $before = db_value(
                            'SELECT id FROM scoring_rondes WHERE traject_id=:t AND leverancier_id=:l AND scope=:s',
                            [':t' => $id, ':l' => $lev, ':s' => $scope]
                        );
                        if (!$before) {
                            ronde_upsert($id, $lev, $scope);
                            $created++;
                        }
                    }
                });
            }
            flash_set('success', "Bulk uitgezet: $created nieuwe ronde(s) aangemaakt (concept).");
        } elseif ($action === 'ronde_open') {
            ronde_set_status(input_int('ronde_id'), $id, 'open');
            flash_set('success', 'Ronde is open — beoordelaars kunnen scoren.');
        } elseif ($action === 'ronde_close') {
            ronde_set_status(input_int('ronde_id'), $id, 'gesloten');
            flash_set('success', 'Ronde gesloten. Scores zijn definitief.');
        } elseif ($action === 'ronde_delete') {
            ronde_delete(input_int('ronde_id'), $id);
            flash_set('success', 'Ronde verwijderd.');
        } elseif ($action === 'invite_send_all') {
            $rid = input_int('ronde_id');
            $res = traject_deelnemers_invite_round($rid, $id);
            flash_set('success', sprintf('Uitnodigingen verzonden: %d nieuw, %d al uitgenodigd.',
                $res['invited'], $res['skipped']));
        } elseif ($action === 'invite_demo') {
            $rid    = input_int('ronde_id');
            $tdIds  = $_POST['td_ids'] ?? [];
            if (!is_array($tdIds)) $tdIds = [];
            $res = traject_deelnemers_invite_round($rid, $id, $tdIds);
            flash_set('success', sprintf('Uitnodigingen verzonden: %d nieuw, %d al uitgenodigd.',
                $res['invited'], $res['skipped']));

        // ─── Collega's (traject-deelnemers) ─────────────────────────────
        } elseif ($action === 'collega_add') {
            $uid = input_int('user_id');
            $u   = $uid ? db_one('SELECT name, email FROM users WHERE id = :id AND active = 1', [':id' => $uid]) : null;
            if (!$u) throw new RuntimeException('Kies een bestaande gebruiker.');
            $scopes = $_POST['scopes'] ?? [];
            if (!is_array($scopes)) $scopes = [];
            traject_deelnemer_create($id, (string)$u['name'], (string)$u['email'], $scopes);
            flash_set('success', 'Collega toegevoegd.');
        } elseif ($action === 'collega_update') {
            // Naam/e-mail komen uit users-tabel; hier alleen scopes herzetten.
            $cid = input_int('collega_id');
            $scopes = $_POST['scopes'] ?? [];
            if (!is_array($scopes)) $scopes = [];
            traject_deelnemer_scopes_set($cid, $scopes);
            flash_set('success', 'Bijgewerkt.');
        } elseif ($action === 'collega_delete') {
            traject_deelnemer_delete(input_int('collega_id'), $id);
            flash_set('success', 'Collega verwijderd.');
        } elseif ($action === 'invite_resend') {
            deelnemer_resend(input_int('deelnemer_id'));
            flash_set('success', 'Nieuwe uitnodiging verstuurd (oude link vervalt).');
        } elseif ($action === 'invite_revoke') {
            deelnemer_delete(input_int('deelnemer_id'));
            flash_set('success', 'Beoordelaar verwijderd.');

        // ─── Leveranciers (per traject) ─────────────────────────────────────
        } elseif ($action === 'lev_create') {
            leverancier_create($id, [
                'name'          => input_str('name'),
                'contact_name'  => input_str('contact_name'),
                'contact_email' => input_str('contact_email'),
                'website'       => input_str('website'),
                'notes'         => input_str('notes'),
                'status'        => input_str('status') ?: 'actief',
            ]);
            flash_set('success', 'Leverancier toegevoegd.');
        } elseif ($action === 'lev_update') {
            leverancier_update(input_int('lev_id'), $id, [
                'name'          => input_str('name'),
                'contact_name'  => input_str('contact_name'),
                'contact_email' => input_str('contact_email'),
                'website'       => input_str('website'),
                'notes'         => input_str('notes'),
                'status'        => input_str('status'),
            ]);
            flash_set('success', 'Leverancier bijgewerkt.');
        } elseif ($action === 'lev_set_status') {
            leverancier_set_status(input_int('lev_id'), $id, input_str('status'));
            flash_set('success', 'Status bijgewerkt.');
        } elseif ($action === 'lev_delete') {
            leverancier_delete(input_int('lev_id'), $id);
            flash_set('success', 'Leverancier verwijderd.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect('pages/traject_detail.php?' . $qs);
}

// ─── Data ─────────────────────────────────────────────────────────────────────
$weights   = weights_load($id);
$structure = structure_load($id);
$demoCatalog = demo_catalog_grouped($id, true);
$collegas     = traject_deelnemers_list($id);
// Gebruikers die nog niet aan dit traject zijn gekoppeld (voor collega-picker)
$koppelbareUsers = db_all(
    "SELECT id, name, email, role FROM users
      WHERE active = 1
        AND LOWER(email) NOT IN (
            SELECT LOWER(email) FROM traject_deelnemers WHERE traject_id = :t
        )
      ORDER BY name",
    [':t' => $id]
);
$leveranciersFull = leveranciers_list($id);

// Scoring-tab data
$leveranciers  = db_all(
    "SELECT id, name, status FROM leveranciers WHERE traject_id = :t ORDER BY name",
    [':t' => $id]
);
$scoringMatrix = scoring_matrix($id);

// Drill-in data: één ronde (leverancier × scope) met beoordelaars + user-picker
$drillRonde = null;
$drillLevName = '';
$drillDeelnemers = [];
$drillUsers = [];
if ($tab === 'scoring' && $drillLev && $drillScope !== '') {
    $levRow = db_one(
        'SELECT id, name FROM leveranciers WHERE id = :l AND traject_id = :t',
        [':l' => $drillLev, ':t' => $id]
    );
    if ($levRow) {
        $drillLevName = (string)$levRow['name'];
        $drillRonde = db_one(
            'SELECT * FROM scoring_rondes
              WHERE traject_id = :t AND leverancier_id = :l AND scope = :s',
            [':t' => $id, ':l' => $drillLev, ':s' => $drillScope]
        );
        if ($drillRonde) {
            $drillDeelnemers = deelnemers_list_for_ronde((int)$drillRonde['id']);
        }
        $drillUsers = db_all(
            "SELECT id, name, email, role FROM users WHERE active = 1 ORDER BY name"
        );
    } else {
        $drillLev = 0; $drillScope = '';
    }
}

$pageTitle  = $traject['name'];
$currentNav = 'trajecten';

$bodyRenderer = function () use (
    $traject, $caps, $canEdit, $canWeight, $weights, $structure, $demoCatalog,
    $collegas, $koppelbareUsers, $leveranciers, $leveranciersFull, $scoringMatrix, $id, $tab, $stab,
    $drillLev, $drillScope, $drillRonde, $drillLevName, $drillDeelnemers, $drillUsers
) {
    // Transparantie: iedereen met traject.view ziet alle tabs.
    $tabs = [
        'details'      => ['title' => 'Details',      'icon' => 'info',    'color' => 'gray'],
        'collegas'     => ['title' => "Collega's",    'icon' => 'users',   'color' => 'blue'],
        'structuur'    => ['title' => 'Structuur',    'icon' => 'layers',  'color' => 'indigo'],
        'scoring'      => ['title' => 'Scoring',      'icon' => 'sliders', 'color' => 'green'],
        'leveranciers' => ['title' => 'Leveranciers', 'icon' => 'package', 'color' => 'green'],
        'weging'       => ['title' => 'Weging',       'icon' => 'sliders', 'color' => 'amber'],
    ];
?>
  <div class="page-header">
    <div>
      <div class="row-sm" style="align-items:center;margin-bottom:4px;">
        <a href="<?= h(APP_BASE_URL) ?>/pages/trajecten.php" class="muted small">← Trajecten</a>
      </div>
      <div class="row-sm" style="align-items:center;gap:10px;">
        <h1 style="margin:0;"><?= h($traject['name']) ?></h1>
        <?= traject_status_badge($traject['status']) ?>
      </div>
      <p class="muted small" style="margin-top:4px;">
        Aangemaakt door <?= h($traject['created_by_name'] ?: 'onbekend') ?>
        op <?= h(date('d-m-Y', strtotime($traject['created_at']))) ?>.
      </p>
    </div>
    <div class="actions">
      <?php if ($caps['traject'] && $traject['status'] !== 'gearchiveerd'): ?>
        <form method="post" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="archive">
          <input type="hidden" name="tab" value="<?= h($tab) ?>">
          <button type="submit" class="btn danger"
                  onclick="return confirm('Traject archiveren?')">
            <?= icon('folder', 14) ?> Archiveren
          </button>
        </form>
      <?php elseif ($caps['traject'] && $traject['status'] === 'gearchiveerd'): ?>
        <form method="post" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="activate">
          <input type="hidden" name="tab" value="<?= h($tab) ?>">
          <button type="submit" class="btn ghost">Heractiveren</button>
        </form>
      <?php endif; ?>
      <?php if ($caps['traject']): ?>
        <button type="button" class="btn danger"
                onclick="document.getElementById('delete-modal').style.display='flex'">
          <?= icon('trash', 14) ?> Verwijderen
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Hoofdtabs -->
  <div class="tabs" style="display:flex;gap:4px;border-bottom:1px solid var(--gray-200);margin-bottom:16px;flex-wrap:wrap;">
    <?php foreach ($tabs as $code => $m):
      $active = ($code === $tab);
      $col    = 'var(--' . $m['color'] . '-600)';
    ?>
      <a href="<?= h(APP_BASE_URL) ?>/pages/traject_detail.php?id=<?= (int)$id ?>&tab=<?= h($code) ?>"
         class="tab<?= $active ? ' active' : '' ?>"
         style="padding:8px 14px;border:1px solid <?= $active ? 'var(--gray-200)' : 'transparent' ?>;border-top:<?= $active ? '3px solid ' . $col : '1px solid transparent' ?>;border-bottom:<?= $active ? '1px solid #fff' : 'none' ?>;border-radius:6px 6px 0 0;margin-bottom:-1px;background:<?= $active ? '#fff' : 'transparent' ?>;color:<?= $active ? $col : 'var(--gray-600)' ?>;text-decoration:none;font-weight:<?= $active ? '600' : '500' ?>;font-size:0.875rem;display:inline-flex;align-items:center;gap:6px;">
        <span style="color:<?= $col ?>;display:inline-flex;"><?= icon($m['icon'], 14) ?></span>
        <?= h($m['title']) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($tab === 'details'): ?>
    <?php render_tab_details($traject, $caps['traject']); ?>
  <?php elseif ($tab === 'collegas'): ?>
    <?php render_tab_collegas($collegas, $caps['collegas'], $id, $koppelbareUsers); ?>
  <?php elseif ($tab === 'structuur'): ?>
    <?php render_tab_structuur($structure, $caps['traject'], $id, $stab, $demoCatalog); ?>
  <?php elseif ($tab === 'scoring'): ?>
    <?php render_tab_scoring(
        $leveranciers, $scoringMatrix, $caps['scoring'], $id,
        $drillLev, $drillScope, $drillRonde, $drillLevName,
        $drillDeelnemers, $drillUsers, $collegas, $caps['enter']
    ); ?>
  <?php elseif ($tab === 'weging'): ?>
    <?php render_tab_weging($structure, $weights, $caps['weging'], $id, $demoCatalog, (float)$traject['demo_weight_pct']); ?>
  <?php elseif ($tab === 'leveranciers'): ?>
    <?php render_tab_leveranciers($leveranciersFull, $caps['lev'], $id); ?>
  <?php endif; ?>

  <!-- Delete-modal -->
  <?php if ($caps['traject']): ?>
    <div id="delete-modal" class="modal-backdrop" style="display:none;"
         onclick="if(event.target===this)this.style.display='none'">
      <div class="modal">
        <div class="modal-header">
          <h2 style="color:var(--red-700);">Traject verwijderen</h2>
          <button type="button" class="btn-icon"
                  onclick="document.getElementById('delete-modal').style.display='none'">
            <?= icon('x', 16) ?>
          </button>
        </div>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="tab" value="<?= h($tab) ?>">
          <div class="modal-body">
            <p class="muted small" style="margin-top:0;">
              Deze actie verwijdert <strong>permanent</strong> alle bijbehorende requirements,
              leveranciers, scoring-rondes en scores. Dit kan niet ongedaan gemaakt worden.
              Archiveren is meestal de betere keuze.
            </p>
            <label class="field">
              Typ ter bevestiging de trajectnaam:
              <span class="hint" style="color:var(--red-700);font-weight:600;">
                <?= h($traject['name']) ?>
              </span>
              <input type="text" name="confirm_name" required autocomplete="off"
                     placeholder="Trajectnaam">
            </label>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn ghost"
                    onclick="document.getElementById('delete-modal').style.display='none'">
              Annuleren
            </button>
            <button type="submit" class="btn danger">
              <?= icon('trash', 14) ?> Definitief verwijderen
            </button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
<?php };

// ─── Renderfuncties per tab ──────────────────────────────────────────────────
function render_tab_details(array $traject, bool $canEdit): void { ?>
  <div class="card" style="border-left:4px solid var(--gray-400);">
    <div class="card-title">
      <h2 style="display:flex;align-items:center;gap:8px;">
        <span style="color:var(--gray-600);display:inline-flex;"><?= icon('info', 16) ?></span>
        Details
        <span class="badge gray">Traject</span>
      </h2>
      <span class="muted small"><?= h(ucfirst((string)$traject['status'])) ?></span>
    </div>
    <p class="muted small" style="margin-top:0;">
      Basisgegevens van dit traject. Naam, periode en status bepalen hoe het traject wordt weergegeven in overzichten en rapportages.
    </p>
    <?php if (!$canEdit): ?>
      <p class="muted small">Alleen-lezen weergave. Bewerken vereist een architect-, admin- of key user-rol.</p>
    <?php endif; ?>
    <form method="post" <?= $canEdit ? '' : 'onsubmit="return false;"' ?>>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="tab" value="details">
      <label class="field">Naam
        <input type="text" name="name" required maxlength="200"
               value="<?= h($traject['name']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
      </label>
      <label class="field">Omschrijving
        <textarea name="description" maxlength="2000" <?= $canEdit ? '' : 'readonly' ?>><?= h($traject['description']) ?></textarea>
      </label>
      <div class="field-row">
        <label class="field">Startdatum
          <input type="date" name="start_date"
                 value="<?= h($traject['start_date']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
        </label>
        <label class="field">Einddatum
          <input type="date" name="end_date"
                 value="<?= h($traject['end_date']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
        </label>
        <label class="field">Status
          <select name="status" class="input" <?= $canEdit ? '' : 'disabled' ?>>
            <?php foreach (TRAJECT_STATUSES as $st): ?>
              <option value="<?= h($st) ?>" <?= $traject['status'] === $st ? 'selected' : '' ?>><?= h(ucfirst($st)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <?php if ($canEdit): ?>
        <button type="submit" class="btn"><?= icon('check', 14) ?> Opslaan</button>
      <?php endif; ?>
    </form>
  </div>
<?php }

function render_tab_collegas(array $collegas, bool $canEdit, int $trajectId, array $koppelbareUsers = []): void {
    $scopes = TD_SCOPES; // FUNC/NFR/VEND/IMPL/SUP/LIC
?>
  <div class="card" style="border-left:4px solid var(--blue-500);">
    <div class="card-title">
      <h2 style="display:flex;align-items:center;gap:8px;">
        <span style="color:var(--blue-600);display:inline-flex;"><?= icon('users', 16) ?></span>
        Collega's
        <span class="badge blue"><?= count($collegas) ?></span>
      </h2>
      <span class="muted small">Beoordelaars die bij dit traject betrokken zijn</span>
    </div>
    <p class="muted small" style="margin-top:0;">
      Koppel hier bestaande gebruikers aan dit traject — key-users, business analisten, architecten, business owners.
      Per collega bepaal je welke hoofdcategorieën zij standaard scoren.
      Bij het openen van een ronde krijgen de aangevinkte collega's een persoonlijke token-link per e-mail.
      Nieuwe gebruikers voeg je eerst toe via <a href="<?= h(APP_BASE_URL) ?>/pages/instellingen.php">Instellingen</a>.
      <br><strong>Demo's</strong> werken anders: daar kies je per ronde wie aanwezig was.
    </p>

    <div class="table-wrap" style="margin-top:12px;">
      <table class="table">
        <thead>
          <tr>
            <th style="width:22%;">Naam</th>
            <th style="width:28%;">E-mail</th>
            <?php foreach ($scopes as $sc):
              $st = requirement_cat_style($sc);
            ?>
              <th style="width:8%;text-align:center;" title="Scoort standaard <?= h($sc) ?>">
                <span style="color:var(--<?= h($st['color']) ?>-600);"><?= h($sc) ?></span>
              </th>
            <?php endforeach; ?>
            <th style="width:10%;text-align:right;">Acties</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$collegas): ?>
            <tr><td colspan="<?= 3 + count($scopes) ?>" class="muted small" style="text-align:center;padding:16px 8px;">
              Nog geen collega's toegevoegd.
            </td></tr>
          <?php else: foreach ($collegas as $c):
            $fid = 'c_' . (int)$c['id'];
            $vinkjes = array_flip($c['scopes']);
          ?>
            <tr>
              <td><strong><?= h((string)$c['name']) ?></strong></td>
              <td class="muted small"><?= h((string)$c['email']) ?></td>
              <?php foreach ($scopes as $sc):
                  $st = requirement_cat_style($sc);
              ?>
                <td style="text-align:center;">
                  <?php if ($canEdit): ?>
                    <input form="<?= h($fid) ?>" type="checkbox" name="scopes[]" value="<?= h($sc) ?>"
                           <?= isset($vinkjes[$sc]) ? 'checked' : '' ?>>
                  <?php elseif (isset($vinkjes[$sc])): ?>
                    <span style="color:var(--<?= h($st['color']) ?>-600);font-weight:700;">&check;</span>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
              <td class="right" style="white-space:nowrap;">
                <?php if ($canEdit): ?>
                  <form id="<?= h($fid) ?>" method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="collega_update">
                    <input type="hidden" name="tab" value="collegas">
                    <input type="hidden" name="collega_id" value="<?= (int)$c['id'] ?>">
                    <button type="submit" class="btn sm ghost" title="Opslaan"><?= icon('check', 12) ?></button>
                  </form>
                  <form method="post" style="display:inline;"
                        onsubmit="return confirm('Collega <?= h((string)$c['name']) ?> verwijderen?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="collega_delete">
                    <input type="hidden" name="tab" value="collegas">
                    <input type="hidden" name="collega_id" value="<?= (int)$c['id'] ?>">
                    <button type="submit" class="btn sm ghost" title="Verwijderen"><?= icon('trash', 12) ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($canEdit): ?>
      <?php if (!$koppelbareUsers): ?>
        <div class="muted small" style="margin-top:14px;padding:12px;background:var(--gray-50);border-radius:6px;">
          Alle actieve gebruikers zijn al aan dit traject gekoppeld.
          Nieuwe gebruikers toevoegen kan via <a href="<?= h(APP_BASE_URL) ?>/pages/instellingen.php">Instellingen</a>.
        </div>
      <?php else: ?>
        <form method="post" style="margin-top:14px;padding:12px;background:var(--gray-50);border-radius:6px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="collega_add">
          <input type="hidden" name="tab" value="collegas">
          <strong style="font-size:0.8125rem;">Collega koppelen</strong>
          <div class="row-sm" style="margin-top:8px;gap:8px;flex-wrap:wrap;align-items:center;">
            <select name="user_id" class="input" required style="flex:1;min-width:240px;">
              <option value="">— kies een gebruiker —</option>
              <?php foreach ($koppelbareUsers as $u): ?>
                <option value="<?= (int)$u['id'] ?>">
                  <?= h($u['name']) ?> &lt;<?= h($u['email']) ?>&gt; · <?= h(user_role_label((string)$u['role'])) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row-sm" style="margin-top:8px;gap:12px;flex-wrap:wrap;align-items:center;">
            <span class="muted small">Scoort:</span>
            <?php foreach ($scopes as $sc):
              $st = requirement_cat_style($sc);
            ?>
              <label style="display:inline-flex;align-items:center;gap:4px;font-size:0.875rem;">
                <input type="checkbox" name="scopes[]" value="<?= h($sc) ?>">
                <span style="color:var(--<?= h($st['color']) ?>-600);font-weight:600;"><?= h($sc) ?></span>
              </label>
            <?php endforeach; ?>
            <button type="submit" class="btn sm" style="margin-left:auto;">
              <?= icon('plus', 12) ?> Koppelen
            </button>
          </div>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php }

function render_tab_structuur(array $structure, bool $canEdit, int $id, string $stab, array $demoCatalog = []): void {
    $subTabs = [
        'FUNC' => ['title' => 'Applicatieservices',     'singular' => 'applicatieservice', 'plural' => 'applicatieservices', 'pill' => 'blue'],
        'NFR'  => ['title' => 'Domeinen',                'singular' => 'domein',            'plural' => 'domeinen',            'pill' => 'amber'],
        'VEND' => ['title' => "Thema's leverancier",     'singular' => 'thema',             'plural' => "thema's",             'pill' => 'green'],
        'IMPL' => ['title' => "Thema's implementatie",   'singular' => 'thema',             'plural' => "thema's",             'pill' => 'cyan2'],
        'SUP'  => ['title' => "Thema's support",         'singular' => 'thema',             'plural' => "thema's",             'pill' => 'violet'],
        'LIC'  => ['title' => "Thema's licenties",       'singular' => 'thema',             'plural' => "thema's",             'pill' => 'red'],
        'DEMO' => ['title' => 'Demo-criteria',           'singular' => 'vraag',             'plural' => 'vragen',              'pill' => 'cyan'],
    ];
    $current = null;
    foreach ($structure as $c) { if ($c['code'] === $stab) { $current = $c; break; } }
    $isDemo = ($stab === 'DEMO');
    $isFunc = ($stab === 'FUNC');
    $meta   = $subTabs[$stab];
?>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;500;600;700;800&display=swap');
  .repo-screen { --pri:#0891b2;--pri-h:#0e7490;--grn:#10b981;--txt:#0d2d3a;--muted:#6b7280;--border:#e2e6ea;--bg:#f4f6f8;--card:#ffffff;--r:12px;--r-sm:6px;--sh:0 1px 3px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.04);--sh-h:0 6px 20px rgba(0,0,0,.1),0 16px 40px rgba(0,0,0,.07);font-family:'Nunito Sans',system-ui,sans-serif;color:var(--txt); }
  .repo-screen h1, .repo-screen h2, .repo-screen p { font-family:inherit; }
  .repo-screen .cpill { padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase; }
  .repo-screen .cpill.cyan   { background:rgba(8,145,178,.10);color:#0891b2; }
  .repo-screen .cpill.blue   { background:rgba(59,130,246,.10);color:#2563eb; }
  .repo-screen .cpill.amber  { background:rgba(245,158,11,.10);color:#b45309; }
  .repo-screen .cpill.green  { background:rgba(16,185,129,.10);color:#059669; }
  .repo-screen .cpill.cyan2  { background:rgba(6,182,212,.10);color:#0e7490; }
  .repo-screen .cpill.violet { background:rgba(139,92,246,.10);color:#7c3aed; }
  .repo-screen .cpill.red    { background:rgba(239,68,68,.10);color:#dc2626; }
  .repo-card { background:var(--card);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--sh);margin-bottom:18px; }
  .repo-tabs { display:flex;gap:2px;padding:6px 10px 0;border-bottom:1px solid var(--border);background:#fff;flex-wrap:wrap;border-radius:var(--r) var(--r) 0 0; }
  .repo-tabs a { display:inline-flex;align-items:center;gap:6px;padding:7px 10px;margin-bottom:-1px;font-size:12px;font-weight:600;text-decoration:none;color:var(--muted);border-bottom:2px solid transparent;border-radius:6px 6px 0 0;transition:color .15s,border-color .15s,background .15s; }
  .repo-tabs a:hover { color:var(--txt);background:rgba(0,0,0,.025); }
  .repo-tabs a.active { color:var(--pri);border-bottom-color:var(--pri);background:rgba(8,145,178,.05); }
  .repo-card-head { padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--border);flex-wrap:wrap; }
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
  .cb { display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:24px;padding:0 8px;border-radius:6px;font-size:12px;font-weight:700; }
  .cb.blue { background:rgba(8,145,178,.10);color:var(--pri); }
  .cb.green { background:rgba(16,185,129,.10);color:var(--grn); }
  .cb.zero { background:#f3f4f6;color:#9ca3af; }
  .itip { position:relative;display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:rgba(8,145,178,.12);color:var(--pri);font-size:10px;font-weight:700;font-style:italic;font-family:'Times New Roman',serif;cursor:help;margin-left:6px;vertical-align:middle;line-height:1; }
  .itip::before { content:'i'; }
  .itip:hover .itip-pop, .itip:focus .itip-pop { opacity:1;visibility:visible;transform:translate(-50%,0); }
  .itip-pop { position:absolute;bottom:calc(100% + 8px);left:50%;transform:translate(-50%,4px);background:#0d2d3a;color:#fff;font-style:normal;font-family:'Nunito Sans',system-ui,sans-serif;font-size:12px;font-weight:500;line-height:1.45;padding:8px 12px;border-radius:6px;width:260px;text-align:left;opacity:0;visibility:hidden;transition:opacity .15s,transform .15s,visibility .15s;pointer-events:none;z-index:10;box-shadow:0 6px 20px rgba(0,0,0,.18); }
  .itip-pop::after { content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);border:5px solid transparent;border-top-color:#0d2d3a; }
  .rb { display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:var(--r-sm);font-size:13px;font-weight:600;border:1px solid transparent;cursor:pointer;text-decoration:none;background:transparent;color:var(--txt);transition:background .15s,border-color .15s,color .15s; }
  .rb:hover { background:rgba(0,0,0,.04); }
  .rb.primary { background:var(--pri);color:#fff;border-color:var(--pri); }
  .rb.primary:hover { background:var(--pri-h);border-color:var(--pri-h); }
  .rb.ghost { border-color:var(--border); }
  .rb.danger { color:#dc2626; }
  .rb.danger:hover { background:rgba(220,38,38,.08); }
  .rb.sm { padding:5px 9px;font-size:12px; }
  .rb[disabled] { opacity:.4;cursor:not-allowed; }
  .repo-search { display:flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--border);border-radius:var(--r-sm);padding:6px 10px;font-size:13px;min-width:240px; }
  .repo-search input { border:0;outline:0;flex:1;font:inherit;color:var(--txt);background:transparent; }
  .repo-search svg { color:var(--muted); }
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
  .demo-block { padding:14px 18px;border-bottom:1px solid var(--border); }
  .demo-block:last-child { border-bottom:0; }
  .demo-block .dh { display:flex;align-items:center;gap:10px;margin-bottom:6px; }
  .demo-block ol { margin:6px 0 0;padding-left:22px;font-size:13.5px;line-height:1.55; }
</style>

<div class="repo-screen">
  <div class="repo-card">
    <nav class="repo-tabs">
      <?php foreach ($subTabs as $code => $m):
        $active = ($code === $stab);
        $count  = 0;
        if ($code === 'DEMO') {
            foreach ($demoCatalog as $blk) $count += count($blk['questions'] ?? []);
        } else {
            foreach ($structure as $c) { if ($c['code'] === $code) { $count = count($c['subs']); break; } }
        }
      ?>
        <a href="<?= h(APP_BASE_URL) ?>/pages/traject_detail.php?id=<?= (int)$id ?>&tab=structuur&stab=<?= h($code) ?>"
           class="<?= $active ? 'active' : '' ?>">
          <span class="cpill <?= h($m['pill']) ?>"><?= h($code) ?></span>
          — <?= h($m['title']) ?>
          <span style="color:var(--muted);font-weight:500;">(<?= $count ?>)</span>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if ($isDemo): ?>
      <?php
        $total = 0;
        foreach ($demoCatalog as $blk) $total += count($blk['questions'] ?? []);
      ?>
      <div class="repo-card-head">
        <div style="color:var(--muted);font-size:12.5px;">
          Demo-vragenlijst voor leveranciers (kopie van master-catalog).
        </div>
        <div style="display:flex;align-items:center;gap:14px;">
          <span class="count"><?= (int)$total ?> <?= $total === 1 ? 'vraag' : 'vragen' ?></span>
          <?php if ($canEdit): ?>
            <a class="rb primary" href="<?= h(APP_BASE_URL) ?>/pages/demo_questions.php?traject_id=<?= (int)$id ?>">
              <?= icon('edit', 14) ?> Demo-vragen beheren
            </a>
          <?php endif; ?>
        </div>
      </div>
      <div class="repo-card-body">
        <?php foreach (DEMO_BLOCKS as $block => $bm):
          $qs = $demoCatalog[$block]['questions'] ?? [];
          $isOpen = demo_block_is_open($block);
        ?>
          <div class="demo-block">
            <div class="dh">
              <span class="cpill <?= $isOpen ? 'cyan' : ($bm['in_total'] ? 'blue' : 'amber') ?>">Blok <?= (int)$block ?></span>
              <strong><?= h($bm['title']) ?></strong>
              <?php if ($isOpen): ?>
                <span style="color:var(--muted);font-size:12px;">· open tekst</span>
              <?php elseif (!$bm['in_total']): ?>
                <span style="color:var(--muted);font-size:12px;">· risico-indicator</span>
              <?php endif; ?>
              <span style="margin-left:auto;color:var(--muted);font-size:12px;"><?= count($qs) ?> vragen</span>
            </div>
            <p style="margin:0;color:var(--muted);font-size:12.5px;"><?= h($bm['subtitle']) ?></p>
            <?php if (!$qs): ?>
              <p style="margin:6px 0 0;color:var(--muted);font-size:12.5px;font-style:italic;">Nog geen vragen in dit blok.</p>
            <?php else: ?>
              <ol>
                <?php foreach ($qs as $q): ?><li><?= h($q['text']) ?></li><?php endforeach; ?>
              </ol>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

    <?php elseif (!$current): ?>
      <div class="repo-card-head"><span class="count">Geen data.</span></div>

    <?php else: ?>
      <?php $rowCount = count($current['subs']); ?>
      <div class="repo-card-head">
        <label class="repo-search">
          <?= icon('search', 14) ?>
          <input type="search" placeholder="Zoek op naam of bron…" oninput="trajStrFilter(this.value)">
        </label>
        <div style="display:flex;align-items:center;gap:14px;">
          <span class="count"><?= $rowCount ?> <?= $rowCount === 1 ? h($meta['singular']) : h($meta['plural']) ?></span>
          <?php if ($canEdit): ?>
            <button type="button" class="rb primary" onclick="trajSubOpen()"><?= icon('plus', 14) ?> Nieuw <?= h($meta['singular']) ?></button>
          <?php endif; ?>
        </div>
      </div>
      <div class="repo-card-body">
        <table class="dt" id="traj-sub-table">
          <colgroup>
            <col>
            <?php if ($isFunc): ?><col style="width:200px;"><?php endif; ?>
            <col style="width:200px;">
            <col style="width:140px;">
            <col style="width:160px;">
          </colgroup>
          <thead>
            <tr>
              <th>Naam</th>
              <?php if ($isFunc): ?><th>Applicatiesoort</th><?php endif; ?>
              <th>Bron</th>
              <th>Requirements</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$current['subs']): ?>
              <tr><td colspan="<?= $isFunc ? 5 : 4 ?>" class="empty">Nog geen <?= h($meta['plural']) ?> — voeg er één toe.</td></tr>
            <?php else: foreach ($current['subs'] as $s):
                $reqCount = (int)db_value(
                    'SELECT COUNT(*) FROM requirements WHERE subcategorie_id = :s',
                    [':s' => (int)$s['id']]
                );
            ?>
              <tr data-search="<?= h(mb_strtolower(($s['name'] ?? '') . ' ' . ($s['bron'] ?? ''))) ?>">
                <td>
                  <span class="name"><?= h($s['name']) ?></span>
                  <?php if (!empty($s['description'])): ?>
                    <span class="itip" tabindex="0" aria-label="Beschrijving"><span class="itip-pop"><?= h($s['description']) ?></span></span>
                  <?php endif; ?>
                </td>
                <?php if ($isFunc): ?>
                  <td class="muted"><?= h((string)($s['app_name'] ?? '')) ?: '<span style="color:#cbd5e1;">—</span>' ?></td>
                <?php endif; ?>
                <td class="muted"><?= h((string)($s['bron'] ?? '')) ?: '<span style="color:#cbd5e1;">—</span>' ?></td>
                <td><span class="cb <?= $reqCount ? 'green' : 'zero' ?>"><?= $reqCount ?></span></td>
                <td style="text-align:right;white-space:nowrap;">
                  <?php if ($canEdit): ?>
                    <button type="button" class="rb sm ghost"
                            data-id="<?= (int)$s['id'] ?>"
                            data-name="<?= h($s['name']) ?>"
                            data-bron="<?= h((string)($s['bron'] ?? '')) ?>"
                            data-desc="<?= h((string)($s['description'] ?? '')) ?>"
                            onclick="trajSubEdit(this)"><?= icon('edit', 12) ?> Bewerken</button>
                    <form method="post" style="display:inline;"
                          onsubmit="return confirm('<?= h(ucfirst($meta['singular'])) ?> &quot;<?= h(addslashes($s['name'])) ?>&quot; verwijderen?<?= $reqCount ? ' Dit kan alleen als er 0 requirements aan hangen.' : '' ?>');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="subcat_delete">
                      <input type="hidden" name="sub_id" value="<?= (int)$s['id'] ?>">
                      <input type="hidden" name="tab" value="structuur">
                      <input type="hidden" name="stab" value="<?= h($stab) ?>">
                      <button type="submit" class="rb sm danger" title="Verwijderen" <?= $reqCount ? 'disabled' : '' ?>>
                        <?= icon('trash', 12) ?>
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($canEdit): ?>
        <!-- Modal: create / update subcategorie -->
        <div class="rmodal-bg" id="traj-sub-modal" onclick="if(event.target===this)trajSubClose()">
          <div class="rmodal">
            <h3 id="traj-sub-title">Nieuw <?= h($meta['singular']) ?></h3>
            <form method="post" autocomplete="off">
              <?= csrf_field() ?>
              <input type="hidden" name="tab" value="structuur">
              <input type="hidden" name="stab" value="<?= h($stab) ?>">
              <input type="hidden" name="categorie_id" value="<?= (int)$current['id'] ?>">
              <input type="hidden" name="action" id="traj-sub-action" value="subcat_add">
              <input type="hidden" name="sub_id" id="traj-sub-id" value="">
              <label class="fl">Naam</label>
              <input type="text" class="fi" name="name" id="traj-sub-name" required maxlength="200" autofocus>
              <label class="fl">Beschrijving <span style="text-transform:none;font-weight:500;color:var(--muted);">(optioneel)</span></label>
              <textarea class="fta" name="description" id="traj-sub-desc" rows="3" maxlength="2000"></textarea>
              <label class="fl">Bron <span style="text-transform:none;font-weight:500;color:var(--muted);">(optioneel)</span></label>
              <input type="text" class="fi" name="bron" id="traj-sub-bron" maxlength="190">
              <div class="actions">
                <button type="button" class="rb ghost" onclick="trajSubClose()">Annuleren</button>
                <button type="submit" class="rb primary" id="traj-sub-submit">Aanmaken</button>
              </div>
            </form>
          </div>
        </div>
        <script>
        (function(){
          const m = document.getElementById('traj-sub-modal');
          const sing = <?= json_encode($meta['singular']) ?>;
          window.trajSubOpen = function(){
            document.getElementById('traj-sub-title').textContent = 'Nieuw ' + sing;
            document.getElementById('traj-sub-action').value = 'subcat_add';
            document.getElementById('traj-sub-id').value = '';
            document.getElementById('traj-sub-name').value = '';
            document.getElementById('traj-sub-desc').value = '';
            document.getElementById('traj-sub-bron').value = '';
            document.getElementById('traj-sub-submit').textContent = 'Aanmaken';
            m && m.classList.add('open');
          };
          window.trajSubEdit = function(btn){
            document.getElementById('traj-sub-title').textContent = sing.charAt(0).toUpperCase()+sing.slice(1)+' bewerken';
            document.getElementById('traj-sub-action').value = 'subcat_update';
            document.getElementById('traj-sub-id').value = btn.dataset.id;
            document.getElementById('traj-sub-name').value = btn.dataset.name;
            document.getElementById('traj-sub-desc').value = btn.dataset.desc || '';
            document.getElementById('traj-sub-bron').value = btn.dataset.bron || '';
            document.getElementById('traj-sub-submit').textContent = 'Opslaan';
            m && m.classList.add('open');
          };
          window.trajSubClose = function(){ m && m.classList.remove('open'); };
          window.trajStrFilter = function(q){
            q = (q||'').toLowerCase().trim();
            document.querySelectorAll('#traj-sub-table tbody tr').forEach(tr => {
              const s = tr.dataset.search || '';
              tr.style.display = (!q || s.indexOf(q) !== -1) ? '' : 'none';
            });
          };
          document.addEventListener('keydown', function(e){
            if (e.key === 'Escape' && m) m.classList.remove('open');
          });
        })();
        </script>
      <?php endif; ?>
    <?php endif; ?>
  </div><!-- /.repo-card -->
</div><!-- /.repo-screen -->
<?php }

function render_tab_scoring(
    array $leveranciers, array $matrix, bool $canEdit, int $id,
    int $drillLev = 0, string $drillScope = '',
    ?array $drillRonde = null, string $drillLevName = '',
    array $drillDeelnemers = [], array $drillUsers = [],
    array $collegas = [], bool $canEnter = false
): void {
    $scopes = [
        'FUNC' => 'Functioneel',
        'NFR'  => 'Non-functioneel',
        'VEND' => 'Leverancier',
        'LIC'  => 'Licentie',
        'SUP'  => 'Support',
        'DEMO' => 'Demo',
    ];
?>
  <div class="card" style="background:var(--gray-50);">
    <h2 style="margin-top:0;display:flex;align-items:center;gap:8px;">
      <span style="color:var(--green-600);display:inline-flex;"><?= icon('sliders', 16) ?></span>
      Scoring-overzicht
    </h2>
    <p style="margin:0 0 4px;">
      Rijen = leveranciers, kolommen = hoofdcategorieën + Demo. Elke cel is één
      scoringsronde voor die combinatie. Klik op een cel om beoordelaars uit te
      nodigen, de ronde te openen, of de status te bekijken.
    </p>
    <p class="muted small" style="margin:0;">
      Leveranciers scoren niet parallel — je kunt de ene verder hebben dan de
      andere. Demo is een apart scoringsspoor (geen requirements, maar demo-criteria).
    </p>
  </div>

  <?php if ($canEdit && $leveranciers): ?>
    <div class="row" style="justify-content:flex-end;margin:12px 0;">
      <button type="button" class="btn"
              onclick="document.getElementById('bulk-rondes-modal').style.display='flex'">
        <?= icon('plus', 14) ?> Rondes uitzetten (bulk)
      </button>
    </div>
  <?php endif; ?>

  <?php if (!$leveranciers): ?>
    <div class="card center" style="padding:40px 20px;">
      <p style="font-size:2.5rem;margin:0 0 8px;">🏢</p>
      <p class="strong">Nog geen leveranciers</p>
      <p class="muted small">Voeg eerst leveranciers toe aan dit traject om te kunnen scoren.</p>
    </div>
    <?php return; ?>
  <?php endif; ?>

  <div class="card" style="padding:0;overflow:auto;">
    <table class="table" style="margin:0;">
      <thead>
        <tr>
          <th style="position:sticky;left:0;background:#fff;z-index:2;min-width:220px;">Leverancier</th>
          <?php foreach ($scopes as $code => $title):
            $style = ronde_scope_style($code);
            $col   = 'var(--' . $style['color'] . '-600)';
          ?>
            <th style="text-align:center;min-width:130px;">
              <span style="display:inline-flex;align-items:center;gap:6px;color:<?= h($col) ?>;">
                <?= icon($style['icon'], 14) ?>
                <span><?= h($code) ?></span>
              </span>
              <div class="muted small" style="font-weight:400;"><?= h($title) ?></div>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($leveranciers as $l):
          $lid = (int)$l['id'];
          $cells = $matrix[$lid] ?? [];
        ?>
          <tr>
            <td style="position:sticky;left:0;background:#fff;z-index:1;">
              <strong><?= h($l['name']) ?></strong>
              <div class="muted small"><?= h(ucfirst((string)$l['status'])) ?></div>
            </td>
            <?php foreach ($scopes as $code => $title):
              $cell = $cells[$code] ?? null;
              $isActive = ($lid === $drillLev && $code === $drillScope);
              render_scoring_cell($cell, $code, $lid, $id, $isActive);
            ?>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Legenda -->
  <div class="card card-compact" style="margin-top:16px;">
    <div class="row" style="flex-wrap:wrap;gap:16px;font-size:0.8125rem;">
      <span><span class="badge gray">—</span> Nog niet uitgezet</span>
      <span><span class="badge gray">Concept</span> Aangemaakt, beoordelaars worden uitgenodigd</span>
      <span><span class="badge green">Open</span> Beoordelaars kunnen scoren</span>
      <span><span class="badge indigo">Gesloten</span> Scores definitief</span>
    </div>
  </div>

  <?php if ($canEdit && $leveranciers): ?>
    <div id="bulk-rondes-modal" class="modal-backdrop" style="display:none;"
         onclick="if(event.target===this)this.style.display='none'">
      <div class="modal" style="max-width:860px;">
        <div class="modal-header">
          <h2>Rondes uitzetten (bulk)</h2>
          <button type="button" class="btn-icon"
                  onclick="document.getElementById('bulk-rondes-modal').style.display='none'">
            <?= icon('x', 16) ?>
          </button>
        </div>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="rondes_bulk">
          <input type="hidden" name="tab" value="scoring">
          <div class="modal-body">
            <p class="muted small" style="margin-top:0;">
              Vink de combinaties aan waarvoor je in één keer een (concept-)ronde wilt
              aanmaken. Bestaande rondes blijven ongewijzigd.
            </p>
            <div style="overflow:auto;">
              <table class="table" style="margin:0;">
                <thead>
                  <tr>
                    <th style="position:sticky;left:0;background:#fff;min-width:200px;">
                      Leverancier
                      <div class="muted small" style="font-weight:400;">
                        <a href="#" onclick="bulkToggleAll(true);return false;">alles</a> ·
                        <a href="#" onclick="bulkToggleAll(false);return false;">geen</a>
                      </div>
                    </th>
                    <?php foreach ($scopes as $code => $title):
                      $st = ronde_scope_style($code);
                      $col = 'var(--' . $st['color'] . '-600)';
                    ?>
                      <th style="text-align:center;min-width:90px;">
                        <span style="color:<?= h($col) ?>;"><?= h($code) ?></span>
                        <div class="muted small" style="font-weight:400;">
                          <a href="#" onclick="bulkToggleCol('<?= h($code) ?>',true);return false;">alle</a><br>
                        </div>
                      </th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($leveranciers as $l):
                    $lid = (int)$l['id'];
                    $cells = $matrix[$lid] ?? [];
                  ?>
                    <tr>
                      <td style="position:sticky;left:0;background:#fff;">
                        <strong><?= h($l['name']) ?></strong>
                      </td>
                      <?php foreach ($scopes as $code => $title):
                        $exists = isset($cells[$code]);
                      ?>
                        <td style="text-align:center;">
                          <?php if ($exists): ?>
                            <span class="muted small" title="bestaat al">✓</span>
                          <?php else: ?>
                            <input type="checkbox"
                                   name="combo[<?= $lid ?>_<?= h($code) ?>]"
                                   value="1"
                                   data-lev="<?= $lid ?>"
                                   data-scope="<?= h($code) ?>">
                          <?php endif; ?>
                        </td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn ghost"
                    onclick="document.getElementById('bulk-rondes-modal').style.display='none'">
              Annuleren
            </button>
            <button type="submit" class="btn"><?= icon('check', 14) ?> Aanmaken</button>
          </div>
        </form>
      </div>
    </div>
    <script>
      function bulkToggleAll(on) {
        document.querySelectorAll('#bulk-rondes-modal input[type=checkbox]')
          .forEach(c => { c.checked = !!on; });
      }
      function bulkToggleCol(scope, on) {
        document.querySelectorAll('#bulk-rondes-modal input[data-scope="'+scope+'"]')
          .forEach(c => { c.checked = !!on; });
      }
    </script>
  <?php endif; ?>

  <?php if ($drillLev && $drillScope !== ''):
      render_scoring_drilldown(
          $id, $drillLev, $drillScope, $drillRonde, $drillLevName,
          $drillDeelnemers, $drillUsers, $canEdit, $collegas
      );
  endif; ?>
<?php }

function render_scoring_drilldown(
    int $trajectId, int $lid, string $scope,
    ?array $ronde, string $levName, array $deelnemers, array $users, bool $canEdit,
    array $collegas = []
): void {
    $style    = ronde_scope_style($scope);
    $colorVar = 'var(--' . $style['color'] . '-600)';
    $scopeLabels = [
        'FUNC' => 'Functioneel', 'NFR' => 'Non-functioneel',
        'VEND' => 'Leverancier', 'IMPL' => 'Implementatie',
        'SUP'  => 'Support',     'LIC'  => 'Licentie',
        'DEMO' => 'Demo',
    ];
    $scopeTitle = $scopeLabels[$scope] ?? $scope;
    $qsBase = 'id=' . $trajectId . '&tab=scoring&leverancier=' . $lid
            . '&scope=' . urlencode($scope);
?>
  <div class="card" style="margin-top:16px;border-left:4px solid <?= h($colorVar) ?>;">
    <div class="card-title" style="align-items:flex-start;">
      <div>
        <h2 style="display:flex;align-items:center;gap:8px;margin:0;">
          <span style="color:<?= h($colorVar) ?>;display:inline-flex;"><?= icon($style['icon'], 18) ?></span>
          <?= h($levName) ?> — <?= h($scope) ?>
          <span class="muted small" style="font-weight:400;"><?= h($scopeTitle) ?></span>
        </h2>
        <?php if ($ronde): ?>
          <div class="row-sm" style="margin-top:6px;gap:8px;align-items:center;">
            <?= ronde_status_badge((string)$ronde['status']) ?>
            <span class="muted small">Ronde: <?= h((string)$ronde['name']) ?></span>
            <?php if ($ronde['end_date']): ?>
              <span class="muted small">· Deadline: <?= h(date('d-m-Y', strtotime((string)$ronde['end_date']))) ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <a href="<?= h(APP_BASE_URL) ?>/pages/traject_detail.php?id=<?= $trajectId ?>&tab=scoring"
         class="btn sm ghost"><?= icon('x', 12) ?> Sluiten</a>
    </div>

    <?php if ($scope === 'DEMO'): ?>
      <p class="muted small" style="margin:8px 0 0;">
        Demo-scoring gebruikt de demo-criteria uit het tabblad Structuur → DEMO.
        Beoordelaars krijgen dezelfde token-link en scoren per criterium 1–5.
      </p>
    <?php endif; ?>

    <?php if (!$ronde): ?>
      <div style="padding:20px 0;">
        <p style="margin:0 0 12px;">
          Er is nog geen ronde uitgezet voor <strong><?= h($levName) ?></strong> op scope
          <strong><?= h($scope) ?></strong>.
        </p>
        <?php if ($canEdit): ?>
          <form method="post" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="ronde_create">
            <input type="hidden" name="tab" value="scoring">
            <input type="hidden" name="leverancier" value="<?= $lid ?>">
            <input type="hidden" name="scope" value="<?= h($scope) ?>">
            <button type="submit" class="btn">
              <?= icon('plus', 14) ?> Ronde aanmaken (concept)
            </button>
          </form>
        <?php else: ?>
          <p class="muted small">Alleen een architect kan rondes aanmaken.</p>
        <?php endif; ?>
      </div>
      <?php return; ?>
    <?php endif; ?>

    <?php $rid = (int)$ronde['id']; $status = (string)$ronde['status']; ?>

    <!-- Status-acties -->
    <?php if ($canEdit && $status !== 'gesloten'): ?>
      <div class="row" style="gap:8px;margin:12px 0;flex-wrap:wrap;">
        <?php if ($status === 'concept'): ?>
          <form method="post" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="ronde_open">
            <input type="hidden" name="tab" value="scoring">
            <input type="hidden" name="leverancier" value="<?= $lid ?>">
            <input type="hidden" name="scope" value="<?= h($scope) ?>">
            <input type="hidden" name="ronde_id" value="<?= $rid ?>">
            <button type="submit" class="btn"
                    onclick="return confirm('Ronde openen? Beoordelaars kunnen daarna scoren.');">
              <?= icon('check', 14) ?> Openstellen voor scoring
            </button>
          </form>
        <?php elseif ($status === 'open'): ?>
          <form method="post" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="ronde_close">
            <input type="hidden" name="tab" value="scoring">
            <input type="hidden" name="leverancier" value="<?= $lid ?>">
            <input type="hidden" name="scope" value="<?= h($scope) ?>">
            <input type="hidden" name="ronde_id" value="<?= $rid ?>">
            <button type="submit" class="btn danger"
                    onclick="return confirm('Ronde sluiten? Scores worden definitief en kunnen niet meer worden aangepast.');">
              <?= icon('check', 14) ?> Ronde afsluiten
            </button>
          </form>
        <?php endif; ?>
        <form method="post" style="display:inline;margin-left:auto;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="ronde_delete">
          <input type="hidden" name="tab" value="scoring">
          <input type="hidden" name="leverancier" value="<?= $lid ?>">
          <input type="hidden" name="scope" value="<?= h($scope) ?>">
          <input type="hidden" name="ronde_id" value="<?= $rid ?>">
          <button type="submit" class="btn sm ghost"
                  onclick="return confirm('Ronde verwijderen? Alle beoordelaars en scores in deze ronde gaan verloren.');">
            <?= icon('trash', 12) ?> Verwijderen
          </button>
        </form>
      </div>
    <?php endif; ?>

    <!-- Beoordelaars -->
    <h3 style="margin:12px 0 6px;font-size:0.9375rem;">Beoordelaars</h3>
    <?php if (!$deelnemers): ?>
      <p class="muted small" style="margin:0 0 10px;">Nog geen beoordelaars uitgenodigd.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr>
            <th>Naam</th><th>E-mail</th><th>Uitgenodigd</th>
            <th>Status</th><th class="right">Acties</th>
          </tr></thead>
          <tbody>
            <?php foreach ($deelnemers as $d): ?>
              <tr>
                <td><?= h((string)$d['name']) ?></td>
                <td class="muted small"><?= h((string)$d['email']) ?></td>
                <td class="muted small">
                  <?= h(date('d-m-Y', strtotime((string)$d['invited_at']))) ?>
                </td>
                <td>
                  <?php if ($d['completed_at']): ?>
                    <span class="badge green">Afgerond</span>
                  <?php elseif (strtotime((string)$d['token_expires']) < time()): ?>
                    <span class="badge gray">Verlopen</span>
                  <?php else: ?>
                    <span class="badge indigo">Uitgenodigd</span>
                    <span class="muted small">(<?= (int)$d['scores'] ?> scores)</span>
                  <?php endif; ?>
                </td>
                <td class="right" style="white-space:nowrap;">
                  <?php if ($canEdit && !$d['completed_at'] && $status === 'open'): ?>
                    <a href="<?= h(APP_BASE_URL) ?>/pages/score.php?deelnemer_id=<?= (int)$d['id'] ?>&admin=1"
                       target="_blank" rel="noopener"
                       class="btn sm ghost"
                       title="Admin: invullen namens deze deelnemer (wordt geaudit)">
                      <?= icon('edit', 12) ?>
                    </a>
                  <?php endif; ?>
                  <?php if ($canEdit && !$d['completed_at'] && $status !== 'gesloten'): ?>
                    <form method="post" style="display:inline;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="invite_resend">
                      <input type="hidden" name="tab" value="scoring">
                      <input type="hidden" name="leverancier" value="<?= $lid ?>">
                      <input type="hidden" name="scope" value="<?= h($scope) ?>">
                      <input type="hidden" name="deelnemer_id" value="<?= (int)$d['id'] ?>">
                      <button type="submit" class="btn sm ghost" title="Nieuwe uitnodiging sturen">
                        <?= icon('mail', 12) ?>
                      </button>
                    </form>
                    <form method="post" style="display:inline;"
                          onsubmit="return confirm('Beoordelaar verwijderen? Reeds ingevulde scores gaan verloren.');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="invite_revoke">
                      <input type="hidden" name="tab" value="scoring">
                      <input type="hidden" name="leverancier" value="<?= $lid ?>">
                      <input type="hidden" name="scope" value="<?= h($scope) ?>">
                      <input type="hidden" name="deelnemer_id" value="<?= (int)$d['id'] ?>">
                      <button type="submit" class="btn sm ghost" title="Verwijderen">
                        <?= icon('trash', 12) ?>
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <!-- Uitnodigingen versturen -->
    <?php if ($canEdit && $status !== 'gesloten'):
      // Bouw lijst kandidaten uit traject-collega's, markeer wie al uitgenodigd is in deze ronde.
      $alreadyInvitedTd = [];
      $alreadyInvitedEmail = [];
      foreach ($deelnemers as $d) {
          if (!empty($d['traject_deelnemer_id'])) $alreadyInvitedTd[(int)$d['traject_deelnemer_id']] = true;
          $alreadyInvitedEmail[mb_strtolower((string)$d['email'])] = true;
      }
      if ($scope === 'DEMO') {
          // DEMO: alle collega's zijn kandidaat, expliciet aanvinken
          $candidates = $collegas;
      } else {
          // Non-DEMO: alleen collega's met deze scope in hun matrix
          $candidates = array_values(array_filter($collegas, fn($c) => in_array($scope, $c['scopes'], true)));
      }
      $openCandidates = array_values(array_filter($candidates, function ($c) use ($alreadyInvitedTd, $alreadyInvitedEmail) {
          return !isset($alreadyInvitedTd[(int)$c['id']])
              && !isset($alreadyInvitedEmail[mb_strtolower((string)$c['email'])]);
      }));
    ?>
      <div class="card card-compact" style="margin-top:16px;background:var(--gray-50);">
        <?php if (!$collegas): ?>
          <p class="muted small" style="margin:0;">
            Nog geen collega's in dit traject.
            <a href="<?= h(APP_BASE_URL) ?>/pages/traject_detail.php?id=<?= (int)$trajectId ?>&tab=collegas">
              Voeg eerst collega's toe →
            </a>
          </p>
        <?php elseif ($scope === 'DEMO'): ?>
          <strong style="font-size:0.8125rem;">Beoordelaars voor deze demo kiezen</strong>
          <p class="muted small" style="margin:4px 0 10px;">
            Vink aan wie bij de demo van <strong><?= h($levName) ?></strong> aanwezig was.
            Al uitgenodigde collega's staan hierboven in de lijst.
          </p>
          <?php if (!$openCandidates): ?>
            <p class="muted small" style="margin:0;">Alle collega's zijn al uitgenodigd voor deze demo.</p>
          <?php else: ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="invite_demo">
              <input type="hidden" name="tab" value="scoring">
              <input type="hidden" name="leverancier" value="<?= $lid ?>">
              <input type="hidden" name="scope" value="<?= h($scope) ?>">
              <input type="hidden" name="ronde_id" value="<?= $rid ?>">
              <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:6px;margin-bottom:10px;">
                <?php foreach ($openCandidates as $c): ?>
                  <label style="display:flex;align-items:center;gap:8px;padding:6px 8px;background:#fff;border:1px solid var(--gray-200);border-radius:4px;font-size:0.875rem;">
                    <input type="checkbox" name="td_ids[]" value="<?= (int)$c['id'] ?>">
                    <span>
                      <strong><?= h((string)$c['name']) ?></strong><br>
                      <span class="muted small"><?= h((string)$c['email']) ?></span>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
              <button type="submit" class="btn sm">
                <?= icon('mail', 12) ?> Uitnodigingen versturen
              </button>
            </form>
          <?php endif; ?>
        <?php elseif (!$candidates): ?>
          <p class="muted small" style="margin:0;">
            Geen collega's hebben <strong><?= h($scope) ?></strong> aangevinkt op de tab <em>Collega's</em>.
            <a href="<?= h(APP_BASE_URL) ?>/pages/traject_detail.php?id=<?= (int)$trajectId ?>&tab=collegas">Aanpassen →</a>
          </p>
        <?php elseif (!$openCandidates): ?>
          <p class="muted small" style="margin:0;">
            Alle collega's met scope <strong><?= h($scope) ?></strong> zijn al uitgenodigd.
          </p>
        <?php else: ?>
          <strong style="font-size:0.8125rem;">Nog uit te nodigen (<?= count($openCandidates) ?>)</strong>
          <ul class="muted small" style="margin:6px 0 10px 18px;padding:0;">
            <?php foreach ($openCandidates as $c): ?>
              <li><?= h((string)$c['name']) ?> <span class="muted small">&lt;<?= h((string)$c['email']) ?>&gt;</span></li>
            <?php endforeach; ?>
          </ul>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="invite_send_all">
            <input type="hidden" name="tab" value="scoring">
            <input type="hidden" name="leverancier" value="<?= $lid ?>">
            <input type="hidden" name="scope" value="<?= h($scope) ?>">
            <input type="hidden" name="ronde_id" value="<?= $rid ?>">
            <button type="submit" class="btn sm">
              <?= icon('mail', 12) ?> Uitnodigingen versturen (<?= count($openCandidates) ?>)
            </button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
<?php }

function render_scoring_cell(?array $cell, string $code, int $lid, int $trajectId, bool $isActive = false): void {
    $href = APP_BASE_URL . '/pages/traject_detail.php?id=' . $trajectId
          . '&tab=scoring&leverancier=' . $lid . '&scope=' . urlencode($code);
    $activeStyle = $isActive
        ? 'background:var(--indigo-50);box-shadow:inset 0 0 0 2px var(--indigo-500);'
        : '';
    if (!$cell) { ?>
      <td style="text-align:center;padding:8px;<?= $activeStyle ?>">
        <a href="<?= h($href) ?>" style="text-decoration:none;color:var(--gray-400);display:block;">—</a>
      </td>
    <?php return; }

    $status     = (string)$cell['status'];
    $deelnemers = (int)$cell['deelnemers'];
    $completed  = (int)$cell['completed'];
    $badge      = ronde_status_badge($status);
    $progress   = $deelnemers
        ? round(100 * $completed / max(1, $deelnemers))
        : 0;
    $barColor = $status === 'gesloten' ? 'var(--indigo-600)' : 'var(--green-600)';
?>
    <td style="text-align:center;vertical-align:middle;padding:8px;<?= $activeStyle ?>">
      <a href="<?= h($href) ?>" style="text-decoration:none;color:inherit;display:block;">
        <?= $badge ?>
        <div class="muted small" style="margin-top:4px;font-variant-numeric:tabular-nums;">
          <?= $completed ?> / <?= $deelnemers ?> klaar
        </div>
        <?php if ($deelnemers): ?>
          <div style="margin-top:4px;background:var(--gray-100);border-radius:999px;height:4px;overflow:hidden;">
            <div style="width:<?= $progress ?>%;background:<?= $barColor ?>;height:100%;"></div>
          </div>
        <?php endif; ?>
      </a>
    </td>
<?php }

function render_tab_weging(array $structure, array $weights, bool $canWeight, int $id, array $demoCatalog = [], float $demoPct = 20.0): void {
    // Bouw JS-data: cat color, subs per cat, namen
    $catMeta = [];
    foreach ($structure as $c) {
        $style = requirement_cat_style($c['code']);
        $catMeta[(int)$c['id']] = [
            'code'  => $c['code'],
            'name'  => $c['name'],
            'color' => $style['color'],
            'subs'  => array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name']], $c['subs']),
        ];
    }
?>
  <!-- Uitleg -->
  <div class="card" style="background:var(--gray-50);">
    <h2 style="margin-top:0;display:flex;align-items:center;gap:8px;">
      <span style="color:var(--amber-600);display:inline-flex;"><?= icon('info', 16) ?></span>
      Hoe werkt weging?
    </h2>
    <p style="margin:0 0 8px;">
      De eindscore van een leverancier wordt bepaald door gewogen middeling van alle requirements.
      Elke requirement hoort bij een <strong>hoofdcategorie</strong> (FUNC, NFR, …) en daarbinnen
      een sub-element (een applicatieservice, domein of thema).
    </p>
    <p style="margin:0 0 8px;">
      Met de sliders hieronder bepaal je <strong>hoe zwaar</strong> elk niveau meetelt:
    </p>
    <ul style="margin:0 0 8px 20px;">
      <li>De <strong>hoofdcategorie-sliders</strong> verdelen 100% over FUNC/NFR/VEND/IMPL/SUP/LIC.</li>
      <li>Per hoofdcategorie verdelen de sub-sliders op hun beurt 100% over de onderliggende elementen.</li>
      <li>Binnen elk niveau herverdelen de andere sliders automatisch mee — de som blijft altijd 100%.</li>
    </ul>
    <p style="margin:0;">
      Onder elke sub-slider zie je een <strong>live-inschatting</strong>:
      wat deze sub telt mee in de eindscore (= hoofdcat% × sub% / 100).
      Zo zie je direct het effect van je keuzes.
    </p>
  </div>

  <?php if (!$canWeight): ?>
    <div class="card">
      <div class="card-title">
        <h2 style="display:flex;align-items:center;gap:8px;">
          <span style="color:var(--amber-600);display:inline-flex;"><?= icon('sliders', 16) ?></span>
          Weging (alleen-lezen)
        </h2>
        <span class="muted small">Je kunt de weging inzien maar niet wijzigen.</span>
      </div>

      <div style="margin-top:14px;">
        <strong style="font-size:0.8125rem;">Hoofdcategorieën</strong>
        <div class="card-compact" style="background:var(--gray-50);border:1px solid var(--gray-100);border-radius:var(--radius);margin-top:6px;">
          <?php foreach ($structure as $c):
              $w = (float)($weights['cats'][(int)$c['id']] ?? 0);
              $style = requirement_cat_style($c['code']);
          ?>
            <div class="weight-row">
              <span class="lbl"><?= h($c['name']) ?></span>
              <div style="flex:1.2;min-width:200px;height:6px;background:var(--gray-200);border-radius:3px;overflow:hidden;">
                <div style="width:<?= h(number_format($w, 1)) ?>%;background:var(--<?= h($style['color']) ?>-600);height:100%;"></div>
              </div>
              <span class="val" style="color:var(--<?= h($style['color']) ?>-600);"><?= h(number_format($w, 1)) ?>%</span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php foreach ($structure as $c):
          if (!$c['subs']) continue;
          $style = requirement_cat_style($c['code']);
          $catW = (float)($weights['cats'][(int)$c['id']] ?? 0);
      ?>
        <div style="margin-top:14px;">
          <strong style="font-size:0.8125rem;display:flex;align-items:center;gap:6px;">
            <span style="color:var(--<?= h($style['color']) ?>-600);display:inline-flex;"><?= icon($style['icon'], 14) ?></span>
            <?= h($c['name']) ?>
          </strong>
          <div class="card-compact" style="background:var(--gray-50);border:1px solid var(--gray-100);border-radius:var(--radius);margin-top:6px;">
            <?php foreach ($c['subs'] as $s):
                $w = (float)($weights['subs'][(int)$s['id']] ?? 0);
                $impact = ($catW * $w) / 100;
            ?>
              <div class="weight-row">
                <span class="lbl"><?= h($s['name']) ?></span>
                <div style="flex:1.2;min-width:200px;height:6px;background:var(--gray-200);border-radius:3px;overflow:hidden;">
                  <div style="width:<?= h(number_format($w, 1)) ?>%;background:var(--<?= h($style['color']) ?>-600);height:100%;"></div>
                </div>
                <span class="val" style="color:var(--<?= h($style['color']) ?>-600);"><?= h(number_format($w, 1)) ?>%</span>
                <span class="impact">Telt <?= h(number_format($impact, 1)) ?>% mee in de eindscore</span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <div style="margin-top:14px;padding:10px 12px;background:var(--gray-50);border-radius:6px;">
        <strong style="font-size:0.8125rem;">Demo-aandeel eindscore:</strong>
        <span style="color:var(--blue-600);font-weight:600;"><?= h(number_format($demoPct, 0)) ?>%</span>
        <span class="muted small"> — requirements wegen voor <?= h(number_format(100-$demoPct, 0)) ?>%.</span>
      </div>
    </div>

    <style>
      .weight-row { display:flex; align-items:center; gap:12px; padding:8px 0; flex-wrap:wrap; }
      .weight-row + .weight-row { border-top:1px dashed var(--gray-200); }
      .weight-row .lbl { flex:1; font-size:0.8125rem; color:var(--gray-700); min-width:180px; }
      .weight-row .val { min-width:62px; text-align:right; font-weight:600; font-variant-numeric: tabular-nums; font-size:0.8125rem; }
      .weight-row .impact { flex-basis:100%; font-size:0.75rem; color:var(--gray-500); font-style:italic; }
    </style>
    <?php return; ?>
  <?php endif; ?>

  <form method="post" class="card" id="weging-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_weights">
    <input type="hidden" name="tab" value="weging">

    <div class="card-title">
      <h2 style="display:flex;align-items:center;gap:8px;">
        <span style="color:var(--amber-600);display:inline-flex;"><?= icon('sliders', 16) ?></span>
        Weging instellen
      </h2>
      <div class="row-sm">
        <button type="submit" class="btn"><?= icon('check', 14) ?> Opslaan</button>
        <button type="submit" name="action" value="reset_weights" class="btn ghost"
                onclick="return confirm('Gewichten terugzetten naar gelijke verdeling?');">
          Reset
        </button>
      </div>
    </div>

    <!-- Hoofdcategorieën -->
    <div style="margin-top:14px;">
      <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:6px;">
        <strong style="font-size:0.8125rem;">Hoofdcategorieën</strong>
        <span class="muted small">Som: <span data-weight-sum>0%</span></span>
      </div>
      <div data-weight-group data-level="cat" class="card-compact"
           style="background:var(--gray-50);border:1px solid var(--gray-100);border-radius:var(--radius);">
        <?php foreach ($structure as $c):
            $w = (float)($weights['cats'][(int)$c['id']] ?? 0);
            $style = requirement_cat_style($c['code']);
        ?>
          <?= weight_slider_row(
                'cat_weight[' . (int)$c['id'] . ']',
                $c['name'],
                $w,
                true,
                $style['color'],
                'data-cat-id="' . (int)$c['id'] . '" data-role="cat"'
          ) ?>
        <?php endforeach; ?>
      </div>
    </div>

    <hr class="hr">

    <!-- Subcategorieën per hoofd -->
    <?php foreach ($structure as $c):
        if (!$c['subs']) continue;
        $style = requirement_cat_style($c['code']);
        $colorVar = 'var(--' . $style['color'] . '-600)';
    ?>
      <div style="margin-top:14px;">
        <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:6px;">
          <strong style="font-size:0.8125rem;display:flex;align-items:center;gap:6px;">
            <span style="color:<?= h($colorVar) ?>;display:inline-flex;"><?= icon($style['icon'], 14) ?></span>
            <?= h($c['name']) ?>
          </strong>
          <span class="muted small">Som: <span data-weight-sum>0%</span></span>
        </div>
        <div data-weight-group data-level="sub" data-cat-id="<?= (int)$c['id'] ?>" class="card-compact"
             style="background:var(--gray-50);border:1px solid var(--gray-100);border-radius:var(--radius);">
          <?php foreach ($c['subs'] as $s):
              $w = (float)($weights['subs'][(int)$s['id']] ?? 0);
          ?>
            <?= weight_slider_row(
                  'sub_weight[' . (int)$s['id'] . ']',
                  $s['name'],
                  $w,
                  true,
                  $style['color'],
                  'data-sub-id="' . (int)$s['id'] . '" data-cat-id="' . (int)$c['id'] . '" data-role="sub"'
            ) ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </form>

  <!-- Demo-weging -->
  <form method="post" class="card" style="margin-top:16px;border-left:4px solid var(--blue-600);">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_demo_weights">
    <input type="hidden" name="tab" value="weging">
    <div class="card-title">
      <h2 style="display:flex;align-items:center;gap:8px;">
        <span style="color:var(--blue-600);display:inline-flex;"><?= icon('monitor', 16) ?></span>
        Demo-weging
      </h2>
      <button type="submit" class="btn sm"><?= icon('check', 12) ?> Opslaan</button>
    </div>
    <p class="muted small" style="margin-top:0;">
      Het demo-aandeel bepaalt hoe zwaar de demo-score meetelt naast de requirements-score
      in de eindscore. De demo-vragenlijst zelf is globaal; binnen de demo wegen de
      blokken 1/2/4 gelijk. Vragen beheren kan een admin via
      <a href="<?= h(APP_BASE_URL) ?>/pages/demo_questions.php">Demo-vragenlijst</a>.
    </p>

    <div class="row" style="gap:16px;align-items:center;margin:10px 0;flex-wrap:wrap;">
      <strong style="font-size:0.8125rem;min-width:160px;">Demo-aandeel eindscore</strong>
      <input type="range" min="0" max="100" step="1"
             name="demo_weight_pct" value="<?= h(number_format($demoPct, 0)) ?>"
             style="flex:1;min-width:200px;accent-color:var(--blue-600);"
             oninput="this.nextElementSibling.textContent=this.value+'%';document.getElementById('req-split-lbl').textContent=(100-this.value)+'%';">
      <span style="min-width:55px;text-align:right;font-weight:600;color:var(--blue-600);">
        <?= h(number_format($demoPct, 0)) ?>%
      </span>
    </div>
    <p class="muted small" style="margin:0 0 12px;">
      Requirements tellen dus voor <span id="req-split-lbl"><?= h(number_format(100-$demoPct, 0)) ?>%</span>
      en demo voor <?= h(number_format($demoPct, 0)) ?>% in de eindscore.
    </p>
  </form>

  <style>
    .weight-row { display:flex; align-items:center; gap:12px; padding:8px 0; flex-wrap:wrap; }
    .weight-row + .weight-row { border-top:1px dashed var(--gray-200); }
    .weight-row .lbl { flex:1; font-size:0.8125rem; color:var(--gray-700); min-width:180px; }
    .weight-row input[type=range] { flex:1.2; min-width:200px; }
    .weight-row .val {
      min-width:62px; text-align:right; font-weight:600;
      font-variant-numeric: tabular-nums; font-size:0.8125rem;
    }
    .weight-row .impact {
      flex-basis:100%;
      margin-left:0;
      padding-left:0;
      font-size:0.75rem;
      color:var(--gray-500);
      font-style:italic;
    }
    [data-weight-sum].off { color: var(--red-600); }
  </style>
  <script src="<?= h(APP_BASE_URL) ?>/public/assets/js/weights.js?v=<?= h(APP_VERSION) ?>-<?= @filemtime(APP_ROOT . '/public/assets/js/weights.js') ?>"></script>
  <script>
    (function () {
      function fmt(n){ return (Math.round(n*10)/10).toFixed(1); }
      function val(el){ return parseFloat(el.value) || 0; }

      function recomputeImpact() {
        document.querySelectorAll('input[data-role=sub]').forEach(sub => {
          const catId = sub.dataset.catId;
          const catEl = document.querySelector('input[data-role=cat][data-cat-id="'+catId+'"]');
          if (!catEl) return;
          const impact = (val(catEl) * val(sub)) / 100;
          const row = sub.closest('.weight-row');
          const tgt = row && row.querySelector('[data-impact]');
          if (tgt) tgt.textContent = 'Telt ' + fmt(impact) + '% mee in de eindscore';
        });
      }

      function hook() {
        document.querySelectorAll('#weging-form input[type=range]').forEach(r => {
          r.addEventListener('input', recomputeImpact);
        });
        recomputeImpact();
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', hook);
      } else {
        hook();
      }
    })();
  </script>
<?php }

/**
 * Eén slider-rij met label, slider, waarde-label en (voor subs) impact-regel.
 */
function weight_slider_row(
    string $inputName,
    string $label,
    float $value,
    bool $enabled,
    string $color = 'indigo',
    string $extraAttrs = ''
): string {
    $valAttr   = number_format($value, 3, '.', '');
    $disabled  = $enabled ? '' : 'disabled';
    $colorVar  = 'var(--' . $color . '-600)';
    $isSub     = str_contains($extraAttrs, 'data-role="sub"');
    $impactRow = $isSub
        ? '<span class="impact" data-impact></span>'
        : '';
    return '<div class="weight-row">'
         .   '<span class="lbl">' . h($label) . '</span>'
         .   '<input type="range" min="0" max="100" step="0.1" '
         .          'value="' . h($valAttr) . '" '
         .          'name="' . h($inputName) . '" '
         .          'style="accent-color:' . h($colorVar) . ';" '
         .          'data-weight '
         .          $extraAttrs . ' '
         .          $disabled . '>'
         .   '<span class="val" data-weight-value style="color:' . h($colorVar) . ';">0%</span>'
         .   $impactRow
         . '</div>';
}

/**
 * Tab: Leveranciers — CRUD binnen het actieve traject.
 */
function render_tab_leveranciers(array $rows, bool $canEdit, int $trajectId): void {
    $statuses = LEVERANCIER_STATUSES;
    $totals = ['all' => count($rows), 'actief' => 0, 'onder_review' => 0, 'afgewezen' => 0];
    foreach ($rows as $r) {
        $s = (string)$r['status'];
        if (isset($totals[$s])) $totals[$s]++;
    }
    // Upload-metadata per leverancier vooraf ophalen
    $uploadsByLev = [];
    $lockedByLev  = [];
    foreach ($rows as $r) {
        $lid = (int)$r['id'];
        $uploadsByLev[$lid] = lev_upload_get($lid);
        $lockedByLev[$lid]  = lev_scoring_started($lid);
    }
?>
  <div class="sc">
    <div class="sc-head" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <div>
        <div class="sc-title">Leveranciers</div>
        <div class="muted small">
          <?= (int)$totals['all'] ?> totaal ·
          <span style="color:#10b981;"><?= (int)$totals['actief'] ?> actief</span> ·
          <span style="color:#f59e0b;"><?= (int)$totals['onder_review'] ?> onder review</span> ·
          <span style="color:#ef4444;"><?= (int)$totals['afgewezen'] ?> afgewezen</span>
        </div>
      </div>
      <?php if ($canEdit): ?>
        <button type="button" class="btn"
                onclick="document.getElementById('lev-create-modal').style.display='flex'">
          <?= icon('plus', 14) ?> Leverancier toevoegen
        </button>
      <?php endif; ?>
    </div>

    <div class="sc-body">
      <?php if (!$rows): ?>
        <p class="muted small" style="margin:0;">Nog geen leveranciers toegevoegd.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th style="width:22%;">Naam</th>
                <th style="width:22%;">Contact</th>
                <th style="width:12%;">Status</th>
                <th style="width:26%;">Antwoorden / upload</th>
                <?php if ($canEdit): ?><th style="width:180px;text-align:right;">Acties</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r):
                $lid    = (int)$r['id'];
                $upload = $uploadsByLev[$lid] ?? null;
                $locked = $lockedByLev[$lid] ?? false;
              ?>
                <tr>
                  <td>
                    <strong><?= h($r['name']) ?></strong>
                    <?php if ($r['website']): ?>
                      <div><a href="<?= h($r['website']) ?>" target="_blank" rel="noopener" class="small muted"><?= h($r['website']) ?></a></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($r['contact_name']): ?>
                      <div><?= h($r['contact_name']) ?></div>
                    <?php endif; ?>
                    <?php if ($r['contact_email']): ?>
                      <div class="muted small"><a href="mailto:<?= h($r['contact_email']) ?>"><?= h($r['contact_email']) ?></a></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?= leverancier_status_badge((string)$r['status']) ?>
                    <?php if (!empty($r['ko_failed_reason'])): ?>
                      <div class="muted small" style="margin-top:4px;" title="<?= h($r['ko_failed_reason']) ?>">KO gefaald</div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($upload): ?>
                      <div class="small">
                        <a href="<?= h(APP_BASE_URL) ?>/pages/lev_antwoorden.php?lev=<?= $lid ?>">Bekijk antwoorden</a>
                      </div>
                      <div class="muted small">
                        <?= h($upload['original_name']) ?><br>
                        <?= h(date('d-m-Y H:i', strtotime($upload['uploaded_at']))) ?>
                        · auto <?= (int)$upload['rows_auto'] ?> · handm. <?= (int)$upload['rows_manual'] ?>
                        <?php if ((int)$upload['rows_ko_fail'] > 0): ?>
                          · <span style="color:#ef4444;font-weight:600;">KO <?= (int)$upload['rows_ko_fail'] ?></span>
                        <?php endif; ?>
                      </div>
                      <?php if ($locked): ?>
                        <div class="muted small" style="color:#6b7280;">🔒 scoring gestart — gelocked</div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="muted small">Geen upload</span>
                    <?php endif; ?>
                  </td>
                  <?php if ($canEdit): ?>
                    <td style="text-align:right;white-space:nowrap;">
                      <a class="btn subtle btn-icon"
                         href="<?= h(APP_BASE_URL) ?>/pages/lev_excel_download.php?lev=<?= $lid ?>"
                         title="Download leeg Excel om naar leverancier te sturen">
                        <?= icon('file-text', 14) ?>
                      </a>
                      <?php if (!$locked): ?>
                        <button type="button" class="btn subtle btn-icon"
                                title="<?= $upload ? 'Overschrijf upload' : 'Upload antwoorden' ?>"
                                onclick="document.getElementById('lev-upload-<?= $lid ?>').style.display='flex'">
                          <?= icon('upload', 14) ?>
                        </button>
                      <?php endif; ?>
                      <?php if ($upload && !$locked): ?>
                        <form method="post"
                              action="<?= h(APP_BASE_URL) ?>/pages/lev_upload_preview.php?lev=<?= $lid ?>"
                              style="display:inline;"
                              onsubmit="return confirm('Upload verwijderen? Dit wist ook alle auto-scores.');">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="delete">
                          <button type="submit" class="btn ghost btn-icon" title="Upload verwijderen">
                            <?= icon('trash', 14) ?>
                          </button>
                        </form>
                      <?php endif; ?>
                      <button type="button" class="btn subtle btn-icon"
                              title="Bewerken"
                              onclick="document.getElementById('lev-edit-<?= $lid ?>').style.display='flex'">
                        <?= icon('edit', 14) ?>
                      </button>
                      <form method="post" style="display:inline;"
                            onsubmit="return confirm('Leverancier verwijderen? Dit verwijdert ook bijbehorende scores.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="lev_delete">
                        <input type="hidden" name="tab" value="leveranciers">
                        <input type="hidden" name="lev_id" value="<?= $lid ?>">
                        <button type="submit" class="btn danger btn-icon" title="Verwijderen leverancier">
                          <?= icon('trash', 14) ?>
                        </button>
                      </form>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($canEdit): ?>
    <!-- Create-modal -->
    <div id="lev-create-modal" class="modal-backdrop" style="display:none;"
         onclick="if(event.target===this)this.style.display='none'">
      <div class="modal">
        <div class="modal-header">
          <h2>Leverancier toevoegen</h2>
          <button type="button" class="btn-icon"
                  onclick="document.getElementById('lev-create-modal').style.display='none'">
            <?= icon('x', 16) ?>
          </button>
        </div>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="lev_create">
          <input type="hidden" name="tab" value="leveranciers">
          <div class="modal-body">
            <label class="field">Naam <span class="hint">*</span>
              <input class="input" name="name" required>
            </label>
            <div class="row">
              <label class="field" style="flex:1;">Contactpersoon
                <input class="input" name="contact_name">
              </label>
              <label class="field" style="flex:1;">E-mail
                <input class="input" type="email" name="contact_email">
              </label>
            </div>
            <label class="field">Website
              <input class="input" name="website" placeholder="https://...">
            </label>
            <label class="field">Notitie
              <textarea class="input" name="notes" rows="3"></textarea>
            </label>
            <label class="field">Status
              <select class="input" name="status">
                <?php foreach ($statuses as $s): ?>
                  <option value="<?= h($s) ?>" <?= $s === 'actief' ? 'selected' : '' ?>><?= h(leverancier_status_label($s)) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn ghost"
                    onclick="document.getElementById('lev-create-modal').style.display='none'">Annuleren</button>
            <button type="submit" class="btn">Toevoegen</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Upload-modals -->
    <?php foreach ($rows as $r):
      $lid = (int)$r['id'];
      if (!empty($lockedByLev[$lid])) continue;
      $hasUpload = !empty($uploadsByLev[$lid]);
    ?>
      <div id="lev-upload-<?= $lid ?>" class="modal-backdrop" style="display:none;"
           onclick="if(event.target===this)this.style.display='none'">
        <div class="modal">
          <div class="modal-header">
            <h2>Antwoorden uploaden — <?= h($r['name']) ?></h2>
            <button type="button" class="btn-icon"
                    onclick="document.getElementById('lev-upload-<?= $lid ?>').style.display='none'">
              <?= icon('x', 16) ?>
            </button>
          </div>
          <form method="post" enctype="multipart/form-data" autocomplete="off"
                action="<?= h(APP_BASE_URL) ?>/pages/lev_upload_preview.php?lev=<?= $lid ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <div class="modal-body">
              <?php if ($hasUpload): ?>
                <div class="flash warning" style="margin-bottom:12px;">
                  Er is al een upload actief. Een nieuwe upload <strong>overschrijft</strong> de bestaande antwoorden en auto-scores.
                </div>
              <?php endif; ?>
              <p class="muted small" style="margin-top:0;">
                Upload het ingevulde Excel-bestand dat de leverancier heeft teruggestuurd (.xlsx).
                Na het inlezen zie je een voorstel met hoeveel rijen automatisch worden gescoord
                en welke handmatig beoordeeld moeten worden.
              </p>
              <label class="field">Excel-bestand (.xlsx) <span class="hint">*</span>
                <input class="input" type="file" name="file" accept=".xlsx" required>
              </label>
              <div class="small muted" style="margin-top:8px;">
                <strong>Auto-scoring regels:</strong><br>
                · Ja zonder toelichting → hoogste score (<?= LEV_SCORE_AUTO_MAX ?>)<br>
                · Nee zonder toelichting → laagste score (<?= LEV_SCORE_AUTO_MIN ?>)<br>
                · Ja/Nee <em>met</em> toelichting → handmatig beoordelen<br>
                · "Deels" → altijd handmatig<br>
                · Knock-out-vragen met Nee zonder toelichting → leverancier krijgt status "Onder review" (waarschuwing, nog niet definitief afgewezen).
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn ghost"
                      onclick="document.getElementById('lev-upload-<?= $lid ?>').style.display='none'">Annuleren</button>
              <button type="submit" class="btn">Uploaden &amp; voorstel bekijken</button>
            </div>
          </form>
        </div>
      </div>
    <?php endforeach; ?>

    <!-- Edit-modals -->
    <?php foreach ($rows as $r): ?>
      <div id="lev-edit-<?= (int)$r['id'] ?>" class="modal-backdrop" style="display:none;"
           onclick="if(event.target===this)this.style.display='none'">
        <div class="modal">
          <div class="modal-header">
            <h2>Leverancier bewerken</h2>
            <button type="button" class="btn-icon"
                    onclick="document.getElementById('lev-edit-<?= (int)$r['id'] ?>').style.display='none'">
              <?= icon('x', 16) ?>
            </button>
          </div>
          <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="lev_update">
            <input type="hidden" name="tab" value="leveranciers">
            <input type="hidden" name="lev_id" value="<?= (int)$r['id'] ?>">
            <div class="modal-body">
              <label class="field">Naam <span class="hint">*</span>
                <input class="input" name="name" required value="<?= h($r['name']) ?>">
              </label>
              <div class="row">
                <label class="field" style="flex:1;">Contactpersoon
                  <input class="input" name="contact_name" value="<?= h((string)($r['contact_name'] ?? '')) ?>">
                </label>
                <label class="field" style="flex:1;">E-mail
                  <input class="input" type="email" name="contact_email" value="<?= h((string)($r['contact_email'] ?? '')) ?>">
                </label>
              </div>
              <label class="field">Website
                <input class="input" name="website" value="<?= h((string)($r['website'] ?? '')) ?>">
              </label>
              <label class="field">Notitie
                <textarea class="input" name="notes" rows="3"><?= h((string)($r['notes'] ?? '')) ?></textarea>
              </label>
              <label class="field">Status
                <select class="input" name="status">
                  <?php foreach ($statuses as $s): ?>
                    <option value="<?= h($s) ?>" <?= $s === $r['status'] ? 'selected' : '' ?>><?= h(leverancier_status_label($s)) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn ghost"
                      onclick="document.getElementById('lev-edit-<?= (int)$r['id'] ?>').style.display='none'">Annuleren</button>
              <button type="submit" class="btn">Opslaan</button>
            </div>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php }

require __DIR__ . '/../templates/layout.php';
