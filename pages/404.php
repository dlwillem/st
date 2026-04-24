<?php
/**
 * Generieke 404-pagina — gebruikt auth-layout om ook zonder login bruikbaar te zijn.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
http_response_code(404);

$pageTitle    = 'Pagina niet gevonden';
$bodyRenderer = function () { ?>
  <h2 style="margin-top:0;">Pagina niet gevonden</h2>
  <p class="muted">De pagina die je zoekt bestaat niet (meer) of is verplaatst.</p>
  <p style="margin-top:14px;">
    <a class="btn" href="<?= h(APP_BASE_URL) ?>/pages/home.php">← Terug naar dashboard</a>
  </p>
<?php };
require __DIR__ . '/../templates/auth_layout.php';
