<?php
/**
 * Beheer demo-vragenlijst — master óf per-traject (via ?traject_id=N).
 *
 * - Zonder traject_id → master-catalog (Structuur stamdata, admin-only)
 * - Met traject_id    → per-traject kopie (beheerd vanuit Structuur→DEMO knop)
 *
 * 5 blokken. Blok 5 = open vragen (tekst, geen 1–5 score, optioneel).
 * Wijzigingen gelden vanaf de eerstvolgende scoring — reeds ingevulde scores blijven.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/demo_catalog.php';
require_once __DIR__ . '/../includes/trajecten.php';
require_login();

$trajectId = input_int('traject_id') ?: null;
$traject   = null;
if ($trajectId !== null) {
    $traject = db_one('SELECT id, name FROM trajecten WHERE id = :id', [':id' => $trajectId]);
    if (!$traject) { flash_set('error', 'Traject niet gevonden.'); redirect('pages/trajecten.php'); }
    if (!can_view_traject($trajectId)) { http_response_code(403); exit('Onvoldoende rechten.'); }
    // Muteren: traject-edit-rechten (zelfde capability als overige traject-structuur wijzigingen)
    $canEdit = can_edit_traject('trajecten.edit', $trajectId);
} else {
    require_can('repository.edit');
    $canEdit = true;
}

$redirectBase = 'pages/demo_questions.php' . ($trajectId ? '?traject_id=' . $trajectId : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    if (!$canEdit) { http_response_code(403); exit('Onvoldoende rechten.'); }
    $action = input_str('action');
    try {
        if ($action === 'create') {
            $block = input_int('block');
            demo_catalog_create($trajectId, $block, input_str('text'));
            flash_set('success', 'Vraag toegevoegd.');
        } elseif ($action === 'update') {
            $qid   = input_int('question_id');
            $block = input_int('block');
            $text  = input_str('text');
            $active = input_str('active') === '1';
            demo_catalog_update($trajectId, $qid, $block, $text, $active);
            flash_set('success', 'Bijgewerkt.');
        } elseif ($action === 'delete') {
            demo_catalog_delete($trajectId, input_int('question_id'));
            flash_set('success', 'Verwijderd.');
        } elseif ($action === 'reorder') {
            $order = $_POST['order'] ?? [];
            if (is_array($order)) {
                db_transaction(function () use ($order, $trajectId) {
                    foreach ($order as $qid => $sortOrder) {
                        demo_catalog_reorder($trajectId, (int)$qid, (int)$sortOrder);
                    }
                });
            }
            flash_set('success', 'Volgorde opgeslagen.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($redirectBase);
}

$grouped = demo_catalog_grouped($trajectId, false); // ook inactieve tonen

$pageTitle  = $trajectId ? ('Demo-vragenlijst — ' . $traject['name']) : 'Demo-vragenlijst (master)';
$currentNav = $trajectId ? 'trajecten' : 'instellingen';

$bodyRenderer = function () use ($grouped, $trajectId, $traject, $canEdit) { ?>
  <div class="page-header">
    <div>
      <?php if ($trajectId): ?>
        <div class="row-sm" style="align-items:center;margin-bottom:4px;">
          <a href="<?= h(APP_BASE_URL) ?>/pages/traject_detail.php?id=<?= (int)$trajectId ?>&tab=structuur&stab=DEMO" class="muted small">← Terug naar traject</a>
        </div>
      <?php endif; ?>
      <h1 style="display:flex;align-items:center;gap:8px;">
        <span style="color:var(--blue-600);display:inline-flex;"><?= icon('monitor', 20) ?></span>
        Demo-vragenlijst
        <?php if ($trajectId): ?>
          <span class="muted" style="font-weight:400;">— <?= h($traject['name']) ?></span>
        <?php else: ?>
          <span class="badge indigo">Master</span>
        <?php endif; ?>
      </h1>
      <p>
        <?php if ($trajectId): ?>
          Per-traject kopie van de demo-vragenlijst. Wijzigingen gelden alleen voor dit traject.
        <?php else: ?>
          Globale master. Nieuwe trajecten krijgen bij aanmaak een eigen kopie hiervan. Bestaande trajecten veranderen hier niet door.
        <?php endif; ?>
      </p>
    </div>
  </div>

  <?php flash_pull(); ?>

  <div class="card" style="background:var(--blue-50);border-left:4px solid var(--blue-600);">
    <p style="margin:0;font-size:0.875rem;">
      <strong>Hoe telt dit mee?</strong> De demo-score is het gemiddelde van de blok-gemiddelden
      van <strong>blok 1, 2 en 4</strong>. <strong>Blok 3 (Risico)</strong> is een aparte indicator:
      onder <?= h(number_format(DEMO_RISK_THRESHOLD, 1)) ?> krijgt de leverancier een ⚠️-vlag.
      <strong>Blok 5 (Open vragen)</strong> zijn vrije tekst-antwoorden die niet meetellen in de score
      en altijd optioneel zijn.
    </p>
  </div>

  <?php foreach (DEMO_BLOCKS as $block => $meta):
    $qs = $grouped[$block]['questions'] ?? [];
    $isOpen = demo_block_is_open($block);
    $color = $isOpen ? 'gray' : ($meta['in_total'] ? 'blue' : 'amber');
  ?>
    <div class="card" style="margin-top:14px;border-left:4px solid var(--<?= h($color) ?>-600);">
      <div class="card-title">
        <h2 style="display:flex;align-items:center;gap:8px;">
          <span class="badge <?= h($color) ?>">Blok <?= (int)$block ?></span>
          <?= h($meta['title']) ?>
          <?php if ($isOpen): ?>
            <span class="badge gray" style="font-size:0.7rem;">Open tekst</span>
          <?php elseif (!$meta['in_total']): ?>
            <span class="badge amber" style="font-size:0.7rem;">Risico-indicator</span>
          <?php endif; ?>
        </h2>
        <span class="muted small"><?= count($qs) ?> vragen</span>
      </div>
      <p class="muted small" style="margin-top:0;"><?= h($meta['subtitle']) ?></p>

      <div class="table-wrap" style="margin-top:8px;">
        <table class="table">
          <thead><tr>
            <th style="width:50px;">#</th>
            <th>Vraag</th>
            <th style="width:90px;">Status</th>
            <?php if ($canEdit): ?><th class="right" style="width:160px;">Acties</th><?php endif; ?>
          </tr></thead>
          <tbody>
            <?php if (!$qs): ?>
              <tr><td colspan="<?= $canEdit ? 4 : 3 ?>" class="muted center" style="padding:16px;">Nog geen vragen in dit blok.</td></tr>
            <?php endif; ?>
            <?php foreach ($qs as $q):
              $fid = 'qu_' . (int)$q['id'];
            ?>
              <tr>
                <?php if ($canEdit): ?>
                  <form id="<?= h($fid) ?>" method="post"></form>
                <?php endif; ?>
                <td class="muted small">#<?= (int)$q['sort_order'] ?></td>
                <td>
                  <?php if ($canEdit): ?>
                    <textarea form="<?= h($fid) ?>" name="text" class="input" rows="2"
                              required maxlength="500" style="margin-top:0;"><?= h($q['text']) ?></textarea>
                    <input form="<?= h($fid) ?>" type="hidden" name="action" value="update">
                    <input form="<?= h($fid) ?>" type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                    <input form="<?= h($fid) ?>" type="hidden" name="block" value="<?= (int)$block ?>">
                    <input form="<?= h($fid) ?>" type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <?php else: ?>
                    <?= h($q['text']) ?>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($canEdit): ?>
                    <label class="row-sm" style="gap:4px;align-items:center;font-size:0.8125rem;cursor:pointer;">
                      <input form="<?= h($fid) ?>" type="checkbox" name="active" value="1" <?= $q['active'] ? 'checked' : '' ?>>
                      Actief
                    </label>
                  <?php else: ?>
                    <span class="badge <?= $q['active'] ? 'green' : 'gray' ?>"><?= $q['active'] ? 'Actief' : 'Inactief' ?></span>
                  <?php endif; ?>
                </td>
                <?php if ($canEdit): ?>
                  <td class="right" style="white-space:nowrap;">
                    <button form="<?= h($fid) ?>" type="submit" class="btn sm"><?= icon('check', 12) ?> Opslaan</button>
                    <form method="post" style="display:inline;"
                          onsubmit="return confirm('Vraag verwijderen? Dit kan alleen als er nog geen scores zijn.');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                      <button type="submit" class="btn sm ghost"><?= icon('trash', 12) ?></button>
                    </form>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($canEdit): ?>
        <form method="post" class="row" style="margin-top:10px;gap:8px;align-items:flex-end;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <input type="hidden" name="block" value="<?= (int)$block ?>">
          <label class="field" style="flex:1;margin-bottom:0;">
            Nieuwe vraag toevoegen aan dit blok
            <input type="text" name="text" maxlength="500" required placeholder="Vraag tekst">
          </label>
          <button type="submit" class="btn"><?= icon('plus', 14) ?> Toevoegen</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php };

require __DIR__ . '/../templates/layout.php';
