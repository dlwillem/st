<?php
/**
 * Logout — alleen POST + CSRF.
 * GET/links triggeren een eenvoudig "bevestig uitloggen" formulier dat
 * vervolgens de POST doet. Zo kan `<img src=".../logout.php">` geen
 * CSRF-DoS meer veroorzaken.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    logout_user();
    flash_set('info', 'Je bent uitgelogd.');
    redirect('pages/login.php');
}

// GET → toon bevestigingsformulier
$pageTitle  = 'Uitloggen';
$currentNav = '';

$bodyRenderer = function () { ?>
  <div class="card" style="max-width:420px;margin:48px auto;text-align:center;">
    <h2 style="margin-top:0;">Uitloggen?</h2>
    <p class="muted small">Je sessie wordt beëindigd en je gaat terug naar het inlogscherm.</p>
    <form method="post" style="margin-top:16px;">
      <?= csrf_field() ?>
      <button type="submit" class="btn">Ja, uitloggen</button>
      <a href="<?= h(APP_BASE_URL) ?>/pages/home.php" class="btn ghost" style="margin-left:8px;">Annuleren</a>
    </form>
  </div>
<?php };

require __DIR__ . '/../templates/layout.php';
