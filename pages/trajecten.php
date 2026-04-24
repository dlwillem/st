<?php
/**
 * Trajecten — overzicht (redesign: traj-grid + traj-card).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/trajecten.php';
require_login();

$statusFilter = input_str('status');
$query        = input_str('q');

$sql    = 'SELECT t.*,
             (SELECT COUNT(*) FROM requirements        r WHERE r.traject_id = t.id) AS req_count,
             (SELECT COUNT(*) FROM leveranciers        l WHERE l.traject_id = t.id) AS lev_count,
             (SELECT COUNT(*) FROM traject_deelnemers  d WHERE d.traject_id = t.id) AS col_count,
             (SELECT COUNT(*) FROM scoring_rondes     sr WHERE sr.traject_id = t.id AND sr.status = "open") AS open_rondes,
             u.name AS created_by_name
           FROM trajecten t
           LEFT JOIN users u ON u.id = t.created_by
           WHERE 1=1';
$params = [];
$allowedIds = user_allowed_traject_ids();
if ($allowedIds !== null) {
    if (empty($allowedIds)) {
        $sql .= ' AND 0';
    } else {
        $sql .= ' AND t.id IN (' . implode(',', array_map('intval', $allowedIds)) . ')';
    }
}
if ($statusFilter && in_array($statusFilter, TRAJECT_STATUSES, true)) {
    $sql .= ' AND t.status = :s';
    $params[':s'] = $statusFilter;
}
if ($query !== '') {
    $sql .= ' AND (t.name LIKE :q OR t.description LIKE :q)';
    $params[':q'] = '%' . $query . '%';
}
$sql .= ' ORDER BY CASE t.status
             WHEN "actief" THEN 0
             WHEN "concept" THEN 1
             WHEN "afgerond" THEN 2
             ELSE 3 END, t.updated_at DESC, t.id DESC';

$trajecten = db_all($sql, $params);

$pageTitle  = 'Trajecten';
$currentNav = 'trajecten';
$canCreate  = can('trajecten.edit');

$bodyRenderer = function () use ($trajecten, $statusFilter, $query, $canCreate) { ?>

  <div class="page-header">
    <div>
      <div class="ph-title">Selectietrajecten</div>
      <div class="ph-sub"><?= count($trajecten) ?> traject<?= count($trajecten) === 1 ? '' : 'en' ?></div>
    </div>
    <div class="actions">
      <?php if ($canCreate): ?>
        <a class="btn" href="<?= h(APP_BASE_URL) ?>/pages/traject_new.php">
          <?= icon('plus', 14) ?> Nieuw traject
        </a>
      <?php endif; ?>
    </div>
  </div>

  <form method="get" class="sbar" style="margin-bottom:16px;">
    <div class="sinp-w" style="flex:1;min-width:220px;">
      <?= icon('search', 14) ?>
      <input type="text" class="sinp" name="q" value="<?= h($query) ?>" placeholder="Zoek op naam of omschrijving…">
    </div>
    <select name="status" class="fsel-sm" onchange="this.form.submit()">
      <option value="">Alle statussen</option>
      <?php foreach (TRAJECT_STATUSES as $st): ?>
        <option value="<?= h($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= h(ucfirst($st)) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn ghost">Filteren</button>
    <?php if ($query !== '' || $statusFilter !== ''): ?>
      <a href="<?= h(APP_BASE_URL) ?>/pages/trajecten.php" class="btn ghost">Reset</a>
    <?php endif; ?>
  </form>

  <?php if (!$trajecten && $query === '' && $statusFilter === ''): ?>
    <div class="traj-grid">
      <?php if ($canCreate): ?>
        <a class="add-card" href="<?= h(APP_BASE_URL) ?>/pages/traject_new.php" style="text-decoration:none;">
          <div style="text-align:center;">
            <div style="width:32px;height:32px;border-radius:8px;background:rgba(14,165,233,.07);display:flex;align-items:center;justify-content:center;margin:0 auto 8px;color:var(--pri);">
              <?= icon('plus', 16) ?>
            </div>
            <div style="font-size:13px;font-weight:600;">Nieuw traject</div>
          </div>
        </a>
      <?php endif; ?>
    </div>
  <?php elseif (!$trajecten): ?>
    <div class="sc" style="text-align:center;padding:40px 20px;">
      <p class="strong" style="margin:0 0 4px;">Geen trajecten gevonden</p>
      <p class="muted small" style="margin:0;">Pas je filters aan of maak een nieuw traject aan.</p>
    </div>
  <?php else: ?>
    <div class="traj-grid">
      <?php foreach ($trajecten as $t):
        $desc = trim((string)$t['description']);
      ?>
        <a class="traj-card" href="<?= h(APP_BASE_URL) ?>/pages/traject_detail.php?id=<?= (int)$t['id'] ?>"
           style="display:block;color:inherit;text-decoration:none;">
          <div class="tc-head">
            <div class="tc-name"><?= h($t['name']) ?></div>
            <?= traject_status_badge($t['status']) ?>
          </div>
          <?php if ($desc !== ''): ?>
            <div class="tc-desc"><?= h(mb_strimwidth($desc, 0, 140, '…')) ?></div>
          <?php endif; ?>
          <div class="tc-meta">
            <span class="tc-mi"><?= icon('package', 12) ?><b><?= (int)$t['lev_count'] ?></b> leveranciers</span>
            <span class="tc-mi"><?= icon('file-text', 12) ?><b><?= (int)$t['req_count'] ?></b> requirements</span>
            <span class="tc-mi"><?= icon('users', 12) ?><b><?= (int)$t['col_count'] ?></b> collega's</span>
            <?php if ((int)$t['open_rondes'] > 0): ?>
              <span class="tc-mi" style="color:#f59e0b;font-weight:700;">
                <?= (int)$t['open_rondes'] ?> open
              </span>
            <?php endif; ?>
          </div>
          <?php if ($t['start_date']): ?>
            <div class="tc-dates">
              <?= h(date('d-m-Y', strtotime($t['start_date']))) ?>
              <?= $t['end_date'] ? ' → ' . h(date('d-m-Y', strtotime($t['end_date']))) : '' ?>
            </div>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
      <?php if ($canCreate && $query === '' && $statusFilter === ''): ?>
        <a class="add-card" href="<?= h(APP_BASE_URL) ?>/pages/traject_new.php" style="text-decoration:none;">
          <div style="text-align:center;">
            <div style="width:32px;height:32px;border-radius:8px;background:rgba(14,165,233,.07);display:flex;align-items:center;justify-content:center;margin:0 auto 8px;color:var(--pri);">
              <?= icon('plus', 16) ?>
            </div>
            <div style="font-size:13px;font-weight:600;">Nieuw traject</div>
          </div>
        </a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

<?php };

require __DIR__ . '/../templates/layout.php';
