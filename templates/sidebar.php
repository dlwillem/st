<?php
/**
 * Zijbalk met logo, navigatie en profiel-dropdown (omhoog).
 * Vereist: $currentNav (string key).
 */
if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

$currentNav = $currentNav ?? '';
$user       = current_user();

// Badges per nav-item (alleen tonen als > 0).
$navBadges = [
    'trajecten' => (int)db_value("SELECT COUNT(*) FROM trajecten WHERE status = 'actief'"),
];

/* Inline SVG's — zelfde set als in de redesign-mockup. */
$svg = [
    'home'         => '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    'trajecten'    => '<path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    'requirements' => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
    'rapportage'   => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
    'faq'          => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    'instellingen' => '<circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 010 14.14M4.93 4.93a10 10 0 000 14.14"/>',
    'repository'   => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
    'audit'        => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'logout'       => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
    'chev-up'      => '<polyline points="18 15 12 9 6 15"/>',
];
$icon = function(string $key) use ($svg): string {
    $p = $svg[$key] ?? '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
};

$navItemsAll = [
    ['key' => 'home',        'label' => 'Home',         'icon' => 'home',         'href' => 'pages/home.php',         'cap' => null],
    ['key' => 'trajecten',   'label' => 'Trajecten',    'icon' => 'trajecten',    'href' => 'pages/trajecten.php',    'cap' => 'traject.view'],
    ['key' => 'requirements','label' => 'Requirements', 'icon' => 'requirements', 'href' => 'pages/requirements.php', 'cap' => 'requirements.edit'],
    ['key' => 'rapportage',  'label' => 'Rapportage',   'icon' => 'rapportage',   'href' => 'pages/rapportage.php',   'cap' => 'rapportage.view'],
    ['key' => 'faq',         'label' => 'FAQ',          'icon' => 'faq',          'href' => 'pages/faq.php',          'cap' => null],
];
$navItems = array_values(array_filter(
    $navItemsAll,
    fn($it) => $it['cap'] === null || can($it['cap'])
));

$ddItemsAll = [
    ['key' => 'instellingen', 'label' => 'Instellingen', 'icon' => 'instellingen', 'href' => 'pages/instellingen.php', 'cap' => 'users.edit'],
    ['key' => 'repository',   'label' => 'Structuur stamdata',   'icon' => 'repository',   'href' => 'pages/repository.php',   'cap' => 'repository.edit'],
    ['key' => 'audit',        'label' => 'Audit trail',  'icon' => 'audit',        'href' => 'pages/audit.php',        'cap' => 'users.edit'],
];
$ddItems = array_values(array_filter($ddItemsAll, fn($it) => can($it['cap'])));

$userName = $user['name'] ?? '?';
?>
<aside class="sidebar" data-nav="<?= h($currentNav) ?>">
  <?php
    $brandApp   = setting_app_name();
    $brandLogo  = setting_logo_url();
    $brandCo    = setting_get('company_name');
    $brandMark  = mb_strtoupper(mb_substr($brandCo !== '' ? $brandCo : $brandApp, 0, 3));
  ?>
  <div class="sidebar-brand">
    <?php if ($brandLogo !== ''): ?>
      <img class="s-logo" src="<?= h($brandLogo) ?>" alt="<?= h($brandApp) ?>"
           style="width:36px;height:36px;object-fit:contain;border-radius:8px;">
    <?php else: ?>
      <div class="s-mark"><?= h($brandMark) ?></div>
    <?php endif; ?>
    <div>
      <strong><?= h($brandCo !== '' ? $brandCo : $brandApp) ?></strong>
      <?php if ($brandCo !== ''): ?><small><?= h($brandApp) ?></small><?php endif; ?>
    </div>
  </div>

  <nav class="sidebar-nav">
    <?php foreach ($navItems as $item): ?>
      <a href="<?= h(APP_BASE_URL) ?>/<?= h($item['href']) ?>"
         class="<?= $currentNav === $item['key'] ? 'active' : '' ?>">
        <?= $icon($item['icon']) ?>
        <span><?= h($item['label']) ?></span>
        <?php $b = $navBadges[$item['key']] ?? 0; if ($b > 0): ?>
          <span class="s-badge"><?= (int)$b ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-user" id="sidebar-user">
    <?php if ($ddItems || true): ?>
      <div class="s-dropdown">
        <?php foreach ($ddItems as $dd): ?>
          <a class="dd-item" href="<?= h(APP_BASE_URL) ?>/<?= h($dd['href']) ?>">
            <?= $icon($dd['icon']) ?><span><?= h($dd['label']) ?></span>
          </a>
        <?php endforeach; ?>
        <?php if ($ddItems): ?><div class="dd-sep"></div><?php endif; ?>
        <form method="post" action="<?= h(APP_BASE_URL) ?>/pages/logout.php" style="margin:0;">
          <?= csrf_field() ?>
          <button type="submit" class="dd-item danger" style="width:100%;background:none;border:0;text-align:left;cursor:pointer;font:inherit;color:inherit;">
            <?= $icon('logout') ?><span>Uitloggen</span>
          </button>
        </form>
      </div>
    <?php endif; ?>
    <div class="s-prof-btn" onclick="document.getElementById('sidebar-user').classList.toggle('open');document.querySelector('#sidebar-user .s-prof-btn').classList.toggle('open');">
      <div class="avatar"><?= h(initials($userName)) ?></div>
      <div class="prof-info">
        <div class="name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($userName) ?></div>
        <div class="role"><?= h($user['role'] ?? '') ?></div>
      </div>
      <span class="prof-chev"><?= $icon('chev-up') ?></span>
    </div>
  </div>
</aside>
<script>
(function(){
  document.addEventListener('click', function(e){
    var wrap = document.getElementById('sidebar-user');
    if (!wrap) return;
    if (wrap.contains(e.target)) return;
    wrap.classList.remove('open');
    var btn = wrap.querySelector('.s-prof-btn');
    if (btn) btn.classList.remove('open');
  });
})();
</script>
