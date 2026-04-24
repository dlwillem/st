<?php
/**
 * Layout voor auth-pagina's (login, wachtwoord reset).
 * Vereist: $pageTitle, $bodyRenderer.
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit('Forbidden'); }
$flashes = flash_pull();
?><!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
  <?php $_appName = setting_app_name(); $_logoUrl = setting_logo_url(); $_favUrl = setting_favicon_url(); $_favMime = setting_favicon_mime(); ?>
  <title><?= h($pageTitle ?? $_appName) ?> — <?= h($_appName) ?></title>
  <?php if ($_favUrl !== ''): ?>
    <link rel="icon" <?= $_favMime !== '' ? 'type="' . h($_favMime) . '"' : '' ?> href="<?= h($_favUrl) ?>?v=<?= @filemtime(APP_ROOT . '/' . setting_get('favicon_path')) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="<?= h(APP_BASE_URL) ?>/public/assets/css/style.css?v=<?= h(APP_VERSION) ?>-<?= @filemtime(APP_ROOT . '/public/assets/css/style.css') ?>">
</head>
<body class="auth">
  <div class="auth-shell">
    <div class="auth-brand">
      <?php if ($_logoUrl !== ''): ?>
        <img src="<?= h($_logoUrl) ?>" alt="<?= h($_appName) ?>">
      <?php endif; ?>
      <h1><?= h($_appName) ?></h1>
      <p>Versie <?= h(APP_VERSION) ?></p>
    </div>

    <?php foreach ($flashes as $f): ?>
      <div class="flash <?= h($f['type']) ?>"><?= h($f['message']) ?></div>
    <?php endforeach; ?>

    <div class="card auth-card">
      <?php $bodyRenderer(); ?>
    </div>
  </div>
</body>
</html>
