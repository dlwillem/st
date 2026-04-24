<?php
/**
 * Wachtwoord reset — via token uit mail. Token is éénmalig bruikbaar.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in()) redirect('pages/home.php');

$token  = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$error  = null;
$done   = false;
$record = null;

if ($token !== '') {
    $record = password_reset_find($token);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $pw1 = (string)($_POST['password']  ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');

    if (!$record) {
        $error = 'Link is ongeldig of verlopen. Vraag een nieuwe aan.';
    } elseif (strlen($pw1) < 8) {
        $error = 'Wachtwoord moet minimaal 8 tekens bevatten.';
    } elseif ($pw1 !== $pw2) {
        $error = 'De wachtwoorden komen niet overeen.';
    } else {
        password_reset_consume((int)$record['id'], (int)$record['user_id'], $pw1);
        audit_log('password_reset_completed', 'user', (int)$record['user_id'], (string)$record['email']);
        $done = true;
        flash_set('success', 'Wachtwoord is bijgewerkt. Log in met je nieuwe wachtwoord.');
    }
}

if ($done) redirect('pages/login.php');

$pageTitle = 'Nieuw wachtwoord instellen';
$bodyRenderer = function () use (&$token, &$error, &$record) { ?>
  <h2>Nieuw wachtwoord instellen</h2>

  <?php if (!$record): ?>
    <div class="flash error" style="margin-bottom:14px;">
      <?= h($error ?? 'Deze reset-link is ongeldig of verlopen.') ?>
    </div>
    <div class="auth-links">
      <a href="<?= h(APP_BASE_URL) ?>/pages/password_forgot.php">
        Nieuwe reset-link aanvragen
      </a>
    </div>
  <?php else: ?>
    <?php if ($error): ?>
      <div class="flash error" style="margin-bottom:14px;"><?= h($error) ?></div>
    <?php endif; ?>
    <p style="font-size:0.875rem;color:var(--gray-500);margin-top:0;">
      Voor <strong><?= h($record['email']) ?></strong>.
    </p>
    <form method="post" autocomplete="off" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <label class="field">Nieuw wachtwoord (min. 8 tekens)
        <input type="password" name="password" minlength="8" required autofocus>
      </label>
      <label class="field">Herhaal wachtwoord
        <input type="password" name="password2" minlength="8" required>
      </label>
      <button type="submit" class="btn">Wachtwoord opslaan</button>
    </form>
  <?php endif; ?>
<?php };

require __DIR__ . '/../templates/auth_layout.php';
