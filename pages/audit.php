<?php
/**
 * Audit-trail viewer — filter op user/actie/entiteit + periode, met paginatie.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('users.edit');

$q       = input_str('q');
$userId  = input_int('user_id');
$action  = input_str('action');
$entity  = input_str('entity_type');
$dateFrom = input_str('date_from');
$dateTo   = input_str('date_to');
$page    = max(1, (int)input_int('page', 1));
$perPage = 50;

$where   = ['1=1'];
$params  = [];
if ($q !== '') {
    $where[] = '(a.detail LIKE :q OR a.user_name LIKE :q OR a.action LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($userId) {
    $where[] = 'a.user_id = :uid';
    $params[':uid'] = $userId;
}
if ($action !== '') {
    $where[] = 'a.action = :act';
    $params[':act'] = $action;
}
if ($entity !== '') {
    $where[] = 'a.entity_type = :et';
    $params[':et'] = $entity;
}
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'a.created_at >= :df';
    $params[':df'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'a.created_at <= :dt';
    $params[':dt'] = $dateTo . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

$total = (int)db_value("SELECT COUNT(*) FROM audit_log a WHERE $whereSql", $params);
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

$rows = db_all(
    "SELECT a.* FROM audit_log a
      WHERE $whereSql
      ORDER BY a.created_at DESC, a.id DESC
      LIMIT $perPage OFFSET $offset",
    $params
);

// Filter-opties
$users      = db_all('SELECT id, name FROM users ORDER BY name');
$actions    = db_all('SELECT DISTINCT action FROM audit_log ORDER BY action');
$entities   = db_all('SELECT DISTINCT entity_type FROM audit_log WHERE entity_type <> "" ORDER BY entity_type');

$pageTitle  = 'Audit trail';
$currentNav = 'audit';

$qs = function (array $overrides = []) use ($q, $userId, $action, $entity, $dateFrom, $dateTo) {
    $base = array_filter([
        'q' => $q, 'user_id' => $userId, 'action' => $action,
        'entity_type' => $entity, 'date_from' => $dateFrom, 'date_to' => $dateTo,
    ], fn($v) => $v !== null && $v !== '');
    return http_build_query(array_merge($base, $overrides));
};

$bodyRenderer = function () use ($rows, $total, $page, $pages, $q, $userId, $action, $entity, $dateFrom, $dateTo, $users, $actions, $entities, $qs) { ?>
  <div class="page-header">
    <div>
      <h1>Audit trail</h1>
      <p><?= (int)$total ?> gebeurtenis<?= $total === 1 ? '' : 'sen' ?></p>
    </div>
  </div>

  <form method="get" class="card card-compact" style="margin-bottom:16px;">
    <div class="row" style="flex-wrap:wrap;gap:8px;align-items:flex-end;">
      <div class="search" style="flex:1;min-width:220px;">
        <?= icon('search', 14) ?>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Zoek in detail, gebruiker of actie…">
      </div>
      <select name="user_id" class="input" style="width:auto;margin-top:0;" onchange="this.form.submit()">
        <option value="">Alle gebruikers</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="action" class="input" style="width:auto;margin-top:0;" onchange="this.form.submit()">
        <option value="">Alle acties</option>
        <?php foreach ($actions as $a): ?>
          <option value="<?= h($a['action']) ?>" <?= $action === $a['action'] ? 'selected' : '' ?>><?= h($a['action']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="entity_type" class="input" style="width:auto;margin-top:0;" onchange="this.form.submit()">
        <option value="">Alle entiteiten</option>
        <?php foreach ($entities as $e): ?>
          <option value="<?= h($e['entity_type']) ?>" <?= $entity === $e['entity_type'] ? 'selected' : '' ?>><?= h($e['entity_type']) ?></option>
        <?php endforeach; ?>
      </select>
      <label class="field" style="margin-bottom:0;">Van
        <input type="date" name="date_from" value="<?= h($dateFrom) ?>">
      </label>
      <label class="field" style="margin-bottom:0;">Tot
        <input type="date" name="date_to" value="<?= h($dateTo) ?>">
      </label>
      <button type="submit" class="btn ghost">Filteren</button>
      <?php if ($q !== '' || $userId || $action !== '' || $entity !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
        <a href="<?= h(APP_BASE_URL) ?>/pages/audit.php" class="btn ghost">Reset</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="card">
    <div class="table-wrap">
      <table class="table">
        <thead><tr>
          <th style="width:150px;">Tijdstip</th>
          <th style="width:140px;">Gebruiker</th>
          <th style="width:130px;">Actie</th>
          <th style="width:100px;">Entiteit</th>
          <th style="width:70px;">ID</th>
          <th>Detail</th>
          <th style="width:120px;">IP</th>
        </tr></thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="7" class="muted center" style="padding:24px;">Geen regels gevonden.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="muted small"><?= h(date('d-m-Y H:i:s', strtotime($r['created_at']))) ?></td>
              <td><?= h($r['user_name'] ?: '—') ?></td>
              <td><code class="small"><?= h($r['action']) ?></code></td>
              <td><?= h($r['entity_type']) ?></td>
              <td class="muted small"><?= $r['entity_id'] ? (int)$r['entity_id'] : '—' ?></td>
              <td class="small" style="word-break:break-word;"><?= h($r['detail']) ?></td>
              <td class="muted small"><code><?= h($r['ip_address']) ?></code></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <div class="row" style="justify-content:space-between;align-items:center;margin-top:10px;">
        <span class="muted small">Pagina <?= $page ?> van <?= $pages ?></span>
        <div class="row-sm">
          <?php if ($page > 1): ?>
            <a class="btn sm ghost" href="?<?= h($qs(['page' => $page - 1])) ?>">← Vorige</a>
          <?php endif; ?>
          <?php if ($page < $pages): ?>
            <a class="btn sm ghost" href="?<?= h($qs(['page' => $page + 1])) ?>">Volgende →</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
<?php };

require __DIR__ . '/../templates/layout.php';
