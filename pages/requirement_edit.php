<?php
/**
 * Requirement — detail/bewerken.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/trajecten.php';
require_once __DIR__ . '/../includes/requirements.php';
require_login();

$id = input_int('id');
if (!$id) redirect('pages/requirements.php');

$req = db_one(
    'SELECT r.*, s.name AS sub_name, c.code AS cat_code, c.name AS cat_name
       FROM requirements r
       JOIN subcategorieen s ON s.id = r.subcategorie_id
       JOIN categorieen    c ON c.id = s.categorie_id
      WHERE r.id = :id',
    [':id' => $id]
);
if (!$req) {
    flash_set('error', 'Requirement niet gevonden.');
    redirect('pages/requirements.php');
}

$trajectId = (int)$req['traject_id'];
$traject   = db_one('SELECT * FROM trajecten WHERE id = :id', [':id' => $trajectId]);
$canEdit   = can('requirements.edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    if (!$canEdit) { http_response_code(403); exit('Onvoldoende rechten.'); }

    $action = input_str('action');
    try {
        if ($action === 'update') {
            requirement_update($id, $trajectId, [
                'title'           => input_str('title'),
                'description'     => input_str('description'),
                'type'            => input_str('type'),
                'subcategorie_id' => input_int('subcategorie_id'),
            ]);
            flash_set('success', 'Requirement bijgewerkt.');
        } elseif ($action === 'delete') {
            requirement_delete($id, $trajectId);
            flash_set('success', 'Requirement verwijderd.');
            redirect('pages/requirements.php');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect('pages/requirement_edit.php?id=' . $id);
}

$subcats = requirement_subcats_for_traject($trajectId);
$subsGrouped = [];
foreach ($subcats as $s) {
    $subsGrouped[$s['cat_code']]['name'] = $s['cat_name'];
    $subsGrouped[$s['cat_code']]['subs'][] = $s;
}

$pageTitle  = $req['code'] . ' — ' . $req['title'];
$currentNav = 'requirements';

$bodyRenderer = function () use ($req, $traject, $subsGrouped, $canEdit) { ?>
  <div class="page-header">
    <div>
      <div class="row-sm" style="align-items:center;margin-bottom:4px;">
        <a href="<?= h(APP_BASE_URL) ?>/pages/requirements.php" class="muted small">← Requirements</a>
      </div>
      <div class="row-sm" style="align-items:center;gap:10px;">
        <h1 style="margin:0;"><code><?= h($req['code']) ?></code> <?= h($req['title']) ?></h1>
        <?= requirement_type_badge($req['type']) ?>
      </div>
      <p class="muted small" style="margin-top:4px;">
        Traject: <strong><?= h($traject['name']) ?></strong> ·
        <?= h($req['cat_name']) ?> — <?= h($req['sub_name']) ?> ·
        Aangemaakt <?= h(date('d-m-Y H:i', strtotime($req['created_at']))) ?>
      </p>
    </div>
  </div>

  <div class="card">
    <div class="card-title"><h2>Details</h2></div>
    <?php if (!$canEdit): ?>
      <p class="muted small">Alleen-lezen weergave.</p>
    <?php endif; ?>
    <form method="post" <?= $canEdit ? '' : 'onsubmit="return false;"' ?>>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">

      <label class="field">Titel
        <input type="text" name="title" required maxlength="300"
               value="<?= h($req['title']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
      </label>
      <label class="field">Omschrijving
        <textarea name="description" rows="5" maxlength="4000"
                  <?= $canEdit ? '' : 'readonly' ?>><?= h($req['description']) ?></textarea>
      </label>
      <div class="field-row">
        <label class="field">Code
          <input type="text" value="<?= h($req['code']) ?>" readonly disabled>
        </label>
        <label class="field">MoSCoW
          <select name="type" class="input" <?= $canEdit ? '' : 'disabled' ?>>
            <?php foreach (REQUIREMENT_TYPES as $t): ?>
              <option value="<?= h($t) ?>" <?= $req['type'] === $t ? 'selected' : '' ?>>
                <?= h(requirement_type_label($t)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">Subcategorie
          <select name="subcategorie_id" class="input" required <?= $canEdit ? '' : 'disabled' ?>>
            <?php foreach ($subsGrouped as $code => $g): ?>
              <optgroup label="<?= h($g['name']) ?>">
                <?php foreach ($g['subs'] as $s): ?>
                  <option value="<?= (int)$s['id'] ?>"
                          <?= (int)$s['id'] === (int)$req['subcategorie_id'] ? 'selected' : '' ?>>
                    <?= h($s['name']) ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <?php if ($canEdit): ?>
        <button type="submit" class="btn"><?= icon('check', 14) ?> Opslaan</button>
      <?php endif; ?>
    </form>
  </div>

  <?php if ($canEdit): ?>
    <div class="card" style="border-color:var(--red-100);background:var(--red-50);margin-top:16px;">
      <div class="card-title"><h2 style="color:var(--red-700);">Verwijderen</h2></div>
      <p class="muted small" style="margin-top:0;">
        Verwijdert het requirement en alle gekoppelde scores. Kan niet ongedaan gemaakt worden.
      </p>
      <form method="post" style="margin-top:10px;"
            onsubmit="return confirm('Requirement <?= h($req['code']) ?> verwijderen?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn danger"><?= icon('trash', 14) ?> Verwijderen</button>
      </form>
    </div>
  <?php endif; ?>
<?php };

require __DIR__ . '/../templates/layout.php';
