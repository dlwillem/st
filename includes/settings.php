<?php
/**
 * Settings — key/value tabel voor app-brede configuratie (branding, mail-config).
 *
 * Schema wordt idempotent aangemaakt bij eerste lookup, zodat bestaande
 * installaties automatisch migreren zonder aparte migrate-stap.
 * Na Fase 5 (install-wizard) neemt die stap het expliciet over.
 */

if (!defined('APP_BOOT')) { http_response_code(403); exit('Forbidden'); }

const SETTING_DEFAULTS = [
    'app_name'       => 'Selectie Tool',
    'company_name'   => '',
    'logo_path'      => '',
    'favicon_path'   => '',
    // Mail — wordt in Fase 2 via de UI ingevuld.
    'mail_driver'    => 'log',
    'mail_from'      => '',
    'mail_from_name' => '',
    'smtp_host'      => '',
    'smtp_port'      => '587',
    'smtp_user'      => '',
    'smtp_secure'    => 'tls',
    'smtp_pwd_enc'   => '', // AES-256-GCM ciphertext (base64)
];

$GLOBALS['__settings_cache'] = null;

function settings_ensure_schema(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db()->exec(
        "CREATE TABLE IF NOT EXISTS settings (
            `key`        VARCHAR(64) NOT NULL PRIMARY KEY,
            `value`      TEXT NOT NULL,
            `updated_at` DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function settings_all(): array {
    if ($GLOBALS['__settings_cache'] !== null) return $GLOBALS['__settings_cache'];
    settings_ensure_schema();
    $rows = db_all('SELECT `key`, `value` FROM settings');
    $out = SETTING_DEFAULTS;
    foreach ($rows as $r) {
        $out[(string)$r['key']] = (string)$r['value'];
    }
    return $GLOBALS['__settings_cache'] = $out;
}

function setting_get(string $key, ?string $default = null): string {
    $all = settings_all();
    if (array_key_exists($key, $all) && $all[$key] !== '') return $all[$key];
    if ($default !== null) return $default;
    return (string)(SETTING_DEFAULTS[$key] ?? '');
}

function setting_set(string $key, string $value): void {
    settings_ensure_schema();
    db()->prepare(
        'INSERT INTO settings (`key`,`value`,`updated_at`) VALUES (:k,:v,:t)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = VALUES(`updated_at`)'
    )->execute([':k' => $key, ':v' => $value, ':t' => date('Y-m-d H:i:s')]);
    $GLOBALS['__settings_cache'] = null;
}

function setting_app_name(): string {
    $n = setting_get('app_name');
    return $n !== '' ? $n : 'Selectie Tool';
}

function setting_logo_url(): string {
    $p = setting_get('logo_path');
    if ($p === '') return '';
    return APP_BASE_URL . '/' . ltrim($p, '/');
}

function setting_favicon_url(): string {
    $p = setting_get('favicon_path');
    if ($p === '') return '';
    return APP_BASE_URL . '/' . ltrim($p, '/');
}

/** MIME-type based on stored favicon extension (for <link type="..."/>). */
function setting_favicon_mime(): string {
    $p = setting_get('favicon_path');
    if ($p === '') return '';
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    return [
        'ico'  => 'image/x-icon',
        'png'  => 'image/png',
        'svg'  => 'image/svg+xml',
    ][$ext] ?? '';
}
