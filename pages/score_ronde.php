<?php
/**
 * Legacy-redirect. Het scoring-overzicht is verhuisd naar de scoring-tab
 * binnen het trajectdetail. Stuur door naar het huidige traject — of
 * naar de trajectenlijst als er geen actief traject is.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/trajecten.php';
require_login();

$urlTrajectId = input_int('traject_id');
if ($urlTrajectId) {
    $exists = db_value('SELECT id FROM trajecten WHERE id = :id', [':id' => $urlTrajectId]);
    if ($exists && can_view_traject((int)$exists)) set_current_traject((int)$exists);
}
$tid = current_traject_id();
if ($tid) {
    redirect('pages/traject_detail.php?id=' . (int)$tid . '&tab=scoring');
} else {
    flash_set('info', 'Kies eerst een traject om de scoring te beheren.');
    redirect('pages/trajecten.php');
}
