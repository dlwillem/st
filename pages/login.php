<?php
/**
 * Login-pagina met CSRF, rate-limiting en audit-logging.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in()) redirect('pages/home.php');

$error      = null;
$email      = '';
$lockedOut  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $email    = trim(mb_strtolower((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Vul e-mail en wachtwoord in.';
    } elseif (login_is_locked_out($email)) {
        $lockedOut = true;
        $error = 'Te veel mislukte pogingen. Probeer het over 15 minuten opnieuw.';
    } else {
        $user = login_attempt($email, $password);
        if ($user) {
            redirect('pages/home.php');
        }
        $remaining = max(0, LOGIN_MAX_ATTEMPTS - login_failed_count($email));
        $error = $remaining > 0
            ? "Onjuiste e-mail of wachtwoord. Nog {$remaining} poging(en) over."
            : 'Onjuiste e-mail of wachtwoord. Account tijdelijk geblokkeerd.';
        if ($remaining === 0) $lockedOut = true;
    }
}

$pageTitle = 'Inloggen';
$bodyRenderer = function () use (&$error, &$email, &$lockedOut) { ?>
  <h2>Inloggen</h2>

  <?php if ($error): ?>
    <div class="flash error" style="margin-bottom:14px;"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post" autocomplete="off" novalidate>
    <?= csrf_field() ?>
    <label class="field">E-mailadres
      <input type="email" name="email" required
             value="<?= h($email) ?>"
             <?= $lockedOut ? 'disabled' : 'autofocus' ?>>
    </label>
    <label class="field">Wachtwoord
      <input type="password" name="password" required
             <?= $lockedOut ? 'disabled' : '' ?>>
    </label>
    <button type="submit" class="btn" <?= $lockedOut ? 'disabled' : '' ?>>
      Inloggen
    </button>
  </form>

  <div class="auth-links">
    <a href="<?= h(APP_BASE_URL) ?>/pages/password_forgot.php">
      Wachtwoord vergeten?
    </a>
  </div>
<?php };

require __DIR__ . '/../templates/auth_layout.php';
