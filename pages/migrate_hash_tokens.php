<?php
/**
 * Eenmalige migratie: hash bestaande plaintext scoring-tokens met SHA-256.
 *
 * Detectie: plaintext = bin2hex(24) = 48 hex-chars. Hash = 64 hex-chars.
 * Bestaande verstuurde mail-links blijven werken (lookup hasht input).
 *
 * Uitvoeren: open /pages/migrate_hash_tokens.php als admin.
 * Het script is idempotent en kan veilig herhaald worden.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/scoring.php';
require_login();
require_can('repository.edit');

header('Content-Type: text/plain; charset=utf-8');

$rows = db_all(
    'SELECT id, token FROM scoring_deelnemers
      WHERE token IS NOT NULL AND CHAR_LENGTH(token) = 48'
);
$done = 0;
foreach ($rows as $r) {
    $hash = deelnemer_token_hash((string)$r['token']);
    db_update('scoring_deelnemers', ['token' => $hash], 'id = :id', [':id' => (int)$r['id']]);
    $done++;
}

echo "Klaar. $done plaintext-tokens gehasht.\n";
audit_log('migrate_hash_tokens', 'scoring_deelnemer', 0, "count=$done");
