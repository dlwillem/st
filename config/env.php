<?php
/**
 * .env-loader — leest key=value uit APP_ROOT/.env zonder extra dependencies.
 *
 * Gebruik via env('KEY', 'default'). Waarden worden ook in $_ENV en getenv()
 * gezet zodat externe libs ze kunnen oppikken.
 *
 * Het .env-bestand MOET buiten de DocumentRoot staan op productie, of
 * minimaal geblokkeerd via .htaccess (zie root .htaccess, FilesMatch).
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

(function () {
    $path = dirname(__DIR__) . '/.env';
    if (!is_file($path) || !is_readable($path)) return;

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;

        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($k === '') continue;

        // Verwijder omringende quotes (single of double)
        if (strlen($v) >= 2) {
            $first = $v[0]; $last = $v[strlen($v) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $v = substr($v, 1, -1);
            }
        }

        // Niet overschrijven als al gezet in de echte environment
        if (getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
})();

function env(string $key, ?string $default = null): ?string {
    $v = getenv($key);
    if ($v === false || $v === '') return $default;
    return $v;
}
