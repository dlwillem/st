<?php
/**
 * AES-256-GCM encryptie voor secrets in de settings-tabel.
 * Sleutel komt uit APP_KEY in .env. Wordt automatisch gegenereerd bij eerste
 * gebruik als het .env-bestand schrijfbaar is.
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

function app_key_get(): ?string {
    static $cached = null;
    if ($cached !== null) return $cached;

    $raw = env('APP_KEY', '');
    if ($raw !== '' && $raw !== null) {
        $bin = _app_key_decode($raw);
        if ($bin !== null) { $cached = $bin; return $cached; }
    }

    // Genereer nieuwe sleutel en probeer naar .env te schrijven.
    $new = random_bytes(32);
    $encoded = 'base64:' . base64_encode($new);
    $path = APP_ROOT . '/.env';
    if (is_file($path) && is_writable($path)) {
        $content = (string)@file_get_contents($path);
        if (preg_match('/^APP_KEY=.*$/m', $content)) {
            $content = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $encoded, $content);
        } else {
            $content = rtrim($content, "\n") . "\nAPP_KEY=" . $encoded . "\n";
        }
        @file_put_contents($path, $content);
    } elseif (!is_file($path) && is_writable(dirname($path))) {
        @file_put_contents($path, "APP_KEY=" . $encoded . "\n");
    }
    putenv('APP_KEY=' . $encoded);
    $_ENV['APP_KEY'] = $encoded;

    $cached = $new;
    return $cached;
}

function _app_key_decode(string $raw): ?string {
    if (str_starts_with($raw, 'base64:')) {
        $b = base64_decode(substr($raw, 7), true);
        return ($b !== false && strlen($b) === 32) ? $b : null;
    }
    // Hex of ruwe 32-byte string fallback
    if (strlen($raw) === 64 && ctype_xdigit($raw)) {
        return hex2bin($raw);
    }
    if (strlen($raw) === 32) return $raw;
    return null;
}

function crypto_encrypt(string $plaintext): string {
    if ($plaintext === '') return '';
    $key = app_key_get();
    if ($key === null) return '';
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) return '';
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

function crypto_decrypt(string $encoded): ?string {
    if ($encoded === '') return null;
    if (!str_starts_with($encoded, 'v1:')) return null;
    $key = app_key_get();
    if ($key === null) return null;
    $bin = base64_decode(substr($encoded, 3), true);
    if ($bin === false || strlen($bin) < 28) return null;
    $iv  = substr($bin, 0, 12);
    $tag = substr($bin, 12, 16);
    $ct  = substr($bin, 28);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? null : $pt;
}
