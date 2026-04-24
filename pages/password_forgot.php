<?php
/**
 * Wachtwoord vergeten — genereert een reset-link en verstuurt via mail-stub.
 *
 * Veiligheid: we geven altijd dezelfde melding, ongeacht of het e-mailadres
 * bestaat, om user-enumeration te voorkomen.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in()) redirect('pages/home.php');

$submitted = false;
$error     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $email = trim(mb_strtolower((string)($_POST['email'] ?? '')));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Vul een geldig e-mailadres in.';
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $recent = (int)db_value(
            "SELECT COUNT(*) FROM audit_log
             WHERE action = 'password_reset_requested'
               AND ip_address = :ip
               AND created_at >= (NOW() - INTERVAL 1 HOUR)",
            [':ip' => $ip]
        );
        $user = db_one('SELECT id, name, email FROM users WHERE email = :e AND active = 1',
                       [':e' => $email]);
        if ($user && $recent < 5) {
            $token    = password_reset_create((int)$user['id'], 60);
            $resetUrl = APP_BASE_URL . '/pages/password_reset.php?token=' . $token;

            $subject = setting_app_name() . ' — wachtwoord opnieuw instellen';
            $html = '<p>Hallo ' . h($user['name']) . ',</p>'
                  . '<p>Klik op onderstaande link om een nieuw wachtwoord in te stellen. '
                  . 'De link is 60 minuten geldig.</p>'
                  . '<p><a href="' . h($resetUrl) . '">' . h($resetUrl) . '</a></p>'
                  . '<p>Heb jij dit niet aangevraagd? Dan kun je deze mail negeren.</p>';
            send_mail($user['email'], $user['name'], $subject, $html);
            audit_log('password_reset_requested', 'user', (int)$user['id'], $user['email']);
        }
        // Altijd dezelfde response
        $submitted = true;
    }
}

$pageTitle = 'Wachtwoord vergeten';
$bodyRenderer = function () use (&$submitted, &$error) { ?>
  <h2>Wachtwoord vergeten</h2>

  <?php if ($submitted): ?>
    <div class="flash success" style="margin-bottom:14px;">
      Als dit e-mailadres bij ons bekend is, ontvang je binnen enkele minuten
      een reset-link.
    </div>
    <div class="auth-links">
      <a href="<?= h(APP_BASE_URL) ?>/pages/login.php">← Terug naar inloggen</a>
    </div>
  <?php else: ?>
    <?php if ($error): ?>
      <div class="flash error" style="margin-bottom:14px;"><?= h($error) ?></div>
    <?php endif; ?>
    <p style="font-size:0.875rem;color:var(--gray-500);margin-top:0;">
      Vul je e-mailadres in. Je ontvangt een link om een nieuw wachtwoord in
      te stellen.
    </p>
    <form method="post" autocomplete="off" novalidate>
      <?= csrf_field() ?>
      <label class="field">E-mailadres
        <input type="email" name="email" required autofocus>
      </label>
      <button type="submit" class="btn">Verstuur reset-link</button>
    </form>
    <div class="auth-links">
      <a href="<?= h(APP_BASE_URL) ?>/pages/login.php">← Terug naar inloggen</a>
    </div>
  <?php endif; ?>
<?php };

require __DIR__ . '/../templates/auth_layout.php';
