<?php
/**
 * Legacy-redirect. Ronde-detail wordt in fase 4 herbouwd op het nieuwe
 * model (leverancier × scope). Stuur voorlopig door naar de scoring-tab.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/trajecten.php';
require_login();

$id = input_int('id');
if ($id) {
    $row = db_one('SELECT traject_id FROM scoring_rondes WHERE id = :id', [':id' => $id]);
    if ($row) {
        redirect('pages/traject_detail.php?id=' . (int)$row['traject_id'] . '&tab=scoring');
    }
}
$tid = current_traject_id();
if ($tid) redirect('pages/traject_detail.php?id=' . (int)$tid . '&tab=scoring');
redirect('pages/trajecten.php');
