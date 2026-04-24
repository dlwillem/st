<?php
/**
 * Topbar — paginatitel + actieslot rechts.
 * Vereist: $pageTitle. Optioneel: $headerActionsHtml (string).
 */
if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }
?>
<header class="topbar">
  <h1><?= h($pageTitle ?? setting_app_name()) ?></h1>
  <div class="topbar-actions">
    <?= $headerActionsHtml ?? '' ?>
  </div>
</header>
