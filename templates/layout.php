<?php
/**
 * Hoofd-layout voor ingelogde pagina's.
 */
if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

$flashes = flash_pull();
?><!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
  <?php $_appName = setting_app_name(); ?>
  <title><?= h($pageTitle ?? $_appName) ?> — <?= h($_appName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= h(APP_BASE_URL) ?>/public/assets/css/style.css?v=<?= h(APP_VERSION) ?>-<?= @filemtime(APP_ROOT . '/public/assets/css/style.css') ?>">
</head>
<body>
  <div class="app-shell">
    <button type="button" class="mobile-hamburger" aria-label="Menu openen"
            onclick="document.body.classList.toggle('sidebar-open')">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="mobile-overlay" onclick="document.body.classList.remove('sidebar-open')"></div>

    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="main-col">
      <main class="content">
        <div class="content-wrap pfade">
          <?php foreach ($flashes as $f): ?>
            <div class="flash <?= h($f['type']) ?>"><?= h($f['message']) ?></div>
          <?php endforeach; ?>

          <?php $bodyRenderer(); ?>
        </div>
      </main>
    </div>
  </div>

  <div id="toasts" class="toast-container"></div>
  <script src="<?= h(APP_BASE_URL) ?>/public/assets/js/app.js?v=<?= h(APP_VERSION) ?>-<?= @filemtime(APP_ROOT . '/public/assets/js/app.js') ?>"></script>
</body>
</html>
