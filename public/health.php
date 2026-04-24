<?php
/**
 * Lichtgewicht health-check endpoint.
 * Geeft 200 OK met JSON bij werkende DB, 503 bij falen.
 * Geen auth (bewust) — geen gevoelige data, alleen ping + status.
 */
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$started = microtime(true);
$status = ['app' => 'ok', 'db' => 'unknown', 'ts' => gmdate('c')];

try {
    $row = db()->query('SELECT 1 AS ok')->fetch();
    $status['db'] = ($row && (int)$row['ok'] === 1) ? 'ok' : 'fail';
} catch (Throwable $e) {
    $status['db'] = 'fail';
}

$status['latency_ms'] = (int)round((microtime(true) - $started) * 1000);

http_response_code($status['db'] === 'ok' ? 200 : 503);
echo json_encode($status, JSON_UNESCAPED_SLASHES);
