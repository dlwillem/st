<?php
/**
 * DKG SelectieTool V2 — globale configuratie
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

// ─── Omgeving ─────────────────────────────────────────────────────────────────
// Fail-safe default is 'production' — wie lokaal wil ontwikkelen zet expliciet
// APP_ENV=development in .env. Zo lekken stacktraces nooit op een productie-host.
define('APP_ENV',      env('APP_ENV', 'production'));
define('APP_NAME',     env('APP_NAME', 'DKG SelectieTool'));
define('APP_VERSION',  '2.0.0');
define('APP_BASE_URL', rtrim(env('APP_BASE_URL', 'http://localhost:8888/st'), '/'));
define('APP_ROOT',     dirname(__DIR__));

// ─── Foutafhandeling ──────────────────────────────────────────────────────────
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', APP_ROOT . '/logs/php_errors.log');
}

// ─── Sessie hardening ─────────────────────────────────────────────────────────
ini_set('session.use_strict_mode',    '1');
ini_set('session.use_only_cookies',   '1');
ini_set('session.cookie_httponly',    '1');
ini_set('session.cookie_samesite',    'Strict');
ini_set('session.cookie_secure',      APP_ENV === 'production' ? '1' : '0');
ini_set('session.gc_maxlifetime',     '7200');   // 2 uur
// __Host- prefix in productie: cookie moet Secure+Path=/ zijn en zonder Domain.
// Beschermt tegen cookie-fixation vanaf subdomeinen.
if (APP_ENV === 'production') {
    ini_set('session.cookie_path',   '/');
    ini_set('session.cookie_domain', '');
    session_name('__Host-DKGSID');
} else {
    session_name('DKGSID');
}

// ─── Tijdzone & locale ────────────────────────────────────────────────────────
date_default_timezone_set('Europe/Amsterdam');
setlocale(LC_TIME, 'nl_NL.UTF-8', 'nl_NL', 'nl');

// ─── Autoload (Composer) ──────────────────────────────────────────────────────
$autoload = APP_ROOT . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

// ─── Upload & security limits ─────────────────────────────────────────────────
define('MAX_UPLOAD_BYTES',  20 * 1024 * 1024);  // 20 MB
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SEC',  900);              // 15 minuten
define('SESSION_ID_TTL',     900);              // 15 min → regenerate
define('SCORE_TOKEN_TTL_DAYS', 30);
