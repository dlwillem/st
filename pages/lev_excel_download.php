<?php
/**
 * Download een leeg Excel voor een leverancier om in te vullen.
 * ?lev=X&lang=nl|en
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/leverancier_excel.php';
require_login();

require_can('leveranciers.edit');

$leverancierId = input_int('lev');
if (!$leverancierId) { http_response_code(400); exit('lev ontbreekt'); }

$lang = input_str('lang') === 'en' ? 'en' : 'nl';

$lev = db_one('SELECT l.id, l.name, l.traject_id, t.name AS traject_name
                 FROM leveranciers l
                 JOIN trajecten t ON t.id = l.traject_id
                WHERE l.id = :id', [':id' => $leverancierId]);
if (!$lev) { http_response_code(404); exit('Leverancier niet gevonden'); }
if (!can_edit_traject('leveranciers.edit', (int)$lev['traject_id'])) {
    http_response_code(403); exit('Onvoldoende rechten voor dit traject.');
}

$safe = preg_replace('/[^A-Za-z0-9._-]+/', '_',
    $lev['traject_name'] . '_' . $lev['name']);
$filename = 'DKG_requirements_' . $safe . '.xlsx';

audit_log('leverancier_excel_downloaded', 'leverancier', $leverancierId, $filename);

leverancier_excel_export($leverancierId, $filename, $lang);
