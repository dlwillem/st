<?php
/**
 * Eenmalige migratie: verwijder de niet meer gebruikte `code`-kolom
 * van `applicatiesoorten` en vervang de unieke index op `code` door
 * een unieke index op `label`.
 *
 * Achtergrond: `applicatiesoorten.code` werd alleen nog gebruikt als
 * lookup-key tijdens de Excel-structuur-import. Die import matcht nu
 * op label. Runtime-code leest de kolom nergens; de seed-loader vulde
 * 'm al niet meer.
 *
 * Uitvoeren: open /pages/migrate_drop_app_code.php als architect.
 * Idempotent en veilig: checkt of de kolom/index nog bestaan voordat
 * er iets gedropt of toegevoegd wordt.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('users.edit');

if (is_demo_mode()) {
    http_response_code(403);
    exit('Schema-migraties zijn uitgeschakeld in de demo-omgeving.');
}

header('Content-Type: text/plain; charset=utf-8');

$pdo  = db();
$dbn  = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

function _col_exists(PDO $pdo, string $db, string $table, string $col): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $st->execute([':s' => $db, ':t' => $table, ':c' => $col]);
    return (int)$st->fetchColumn() > 0;
}

function _index_exists(PDO $pdo, string $db, string $table, string $idx): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t AND INDEX_NAME = :i'
    );
    $st->execute([':s' => $db, ':t' => $table, ':i' => $idx]);
    return (int)$st->fetchColumn() > 0;
}

$steps = [];

// 1. Drop unique index on code (if still present)
if (_index_exists($pdo, $dbn, 'applicatiesoorten', 'uq_app_code')) {
    $pdo->exec('ALTER TABLE applicatiesoorten DROP INDEX uq_app_code');
    $steps[] = 'Index uq_app_code gedropt.';
} else {
    $steps[] = 'Index uq_app_code niet aanwezig (overgeslagen).';
}

// 2. Drop code column (if still present)
if (_col_exists($pdo, $dbn, 'applicatiesoorten', 'code')) {
    $pdo->exec('ALTER TABLE applicatiesoorten DROP COLUMN code');
    $steps[] = 'Kolom code gedropt.';
} else {
    $steps[] = 'Kolom code niet aanwezig (overgeslagen).';
}

// 3. Add unique index on label (if missing)
if (!_index_exists($pdo, $dbn, 'applicatiesoorten', 'uq_app_label')) {
    // Eerst controleren of er geen duplicate labels zijn
    $dup = db_all(
        'SELECT label, COUNT(*) AS n FROM applicatiesoorten
          GROUP BY label HAVING n > 1'
    );
    if ($dup) {
        echo "FOUT: dubbele labels gevonden, kan unique index niet toevoegen:\n";
        foreach ($dup as $d) echo "  - '{$d['label']}' ({$d['n']}x)\n";
        echo "\nLos eerst de duplicaten op en draai de migratie opnieuw.\n";
        exit;
    }
    $pdo->exec('ALTER TABLE applicatiesoorten ADD UNIQUE KEY uq_app_label (label)');
    $steps[] = 'Index uq_app_label toegevoegd.';
} else {
    $steps[] = 'Index uq_app_label al aanwezig (overgeslagen).';
}

echo "Migratie voltooid:\n";
foreach ($steps as $s) echo "  - $s\n";

audit_log('migrate_drop_app_code', 'applicatiesoorten', 0, implode(' | ', $steps));
