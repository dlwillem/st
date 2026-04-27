<?php
/**
 * Eenmalige migratie:
 *   1. Hernoem `applicatiesoorten.label` → `applicatiesoorten.name`
 *      (incl. unique index `uq_app_label` → `uq_app_name`).
 *   2. Voeg `bron` (varchar 190 NULL) toe aan `applicatiesoorten` en
 *      `subcategorie_templates`.
 *   3. Voeg de nieuwe hoofdcategorie `IMPL` (Implementatie) toe aan
 *      `categorieen` als die nog niet bestaat.
 *
 * Idempotent: alle stappen checken eerst of de wijziging al doorgevoerd
 * is voordat ze iets uitvoeren.
 *
 * Uitvoeren: open /pages/migrate_add_impl_bron.php als architect.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('users.edit');

if (is_demo_mode()) {
    http_response_code(403);
    exit('Schema-migraties zijn uitgeschakeld in de demo-omgeving.');
}

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$dbn = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

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

// 1. applicatiesoorten.label → applicatiesoorten.name (+ index)
if (_col_exists($pdo, $dbn, 'applicatiesoorten', 'label')
    && !_col_exists($pdo, $dbn, 'applicatiesoorten', 'name')) {
    if (_index_exists($pdo, $dbn, 'applicatiesoorten', 'uq_app_label')) {
        $pdo->exec('ALTER TABLE applicatiesoorten DROP INDEX uq_app_label');
        $steps[] = 'Index uq_app_label gedropt.';
    }
    $pdo->exec(
        "ALTER TABLE applicatiesoorten
         CHANGE COLUMN `label` `name` varchar(200)
           COLLATE utf8mb4_unicode_ci NOT NULL"
    );
    $steps[] = 'Kolom applicatiesoorten.label hernoemd naar name.';
} else {
    $steps[] = 'Kolom applicatiesoorten.name al aanwezig (rename overgeslagen).';
}

if (!_index_exists($pdo, $dbn, 'applicatiesoorten', 'uq_app_name')) {
    $dup = db_all('SELECT name, COUNT(*) AS n FROM applicatiesoorten GROUP BY name HAVING n > 1');
    if ($dup) {
        echo "FOUT: dubbele applicatiesoort-namen gevonden, kan unique index niet toevoegen:\n";
        foreach ($dup as $d) echo "  - '{$d['name']}' ({$d['n']}x)\n";
        echo "\nLos eerst de duplicaten op en draai de migratie opnieuw.\n";
        exit;
    }
    $pdo->exec('ALTER TABLE applicatiesoorten ADD UNIQUE KEY uq_app_name (name)');
    $steps[] = 'Index uq_app_name toegevoegd.';
} else {
    $steps[] = 'Index uq_app_name al aanwezig (overgeslagen).';
}

// 2. bron-kolom op applicatiesoorten
if (!_col_exists($pdo, $dbn, 'applicatiesoorten', 'bron')) {
    $pdo->exec(
        "ALTER TABLE applicatiesoorten
         ADD COLUMN `bron` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL
         AFTER `description`"
    );
    $steps[] = 'Kolom applicatiesoorten.bron toegevoegd.';
} else {
    $steps[] = 'Kolom applicatiesoorten.bron al aanwezig (overgeslagen).';
}

// 3. bron-kolom op subcategorie_templates
if (!_col_exists($pdo, $dbn, 'subcategorie_templates', 'bron')) {
    $pdo->exec(
        "ALTER TABLE subcategorie_templates
         ADD COLUMN `bron` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL
         AFTER `name`"
    );
    $steps[] = 'Kolom subcategorie_templates.bron toegevoegd.';
} else {
    $steps[] = 'Kolom subcategorie_templates.bron al aanwezig (overgeslagen).';
}

// 3b. description-kolom op subcategorie_templates
if (!_col_exists($pdo, $dbn, 'subcategorie_templates', 'description')) {
    $pdo->exec(
        "ALTER TABLE subcategorie_templates
         ADD COLUMN `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL
         AFTER `bron`"
    );
    $steps[] = 'Kolom subcategorie_templates.description toegevoegd.';
} else {
    $steps[] = 'Kolom subcategorie_templates.description al aanwezig (overgeslagen).';
}

// 3c. bron + description-kolommen op subcategorieen (per-traject)
if (!_col_exists($pdo, $dbn, 'subcategorieen', 'bron')) {
    $pdo->exec(
        "ALTER TABLE subcategorieen
         ADD COLUMN `bron` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL
         AFTER `name`"
    );
    $steps[] = 'Kolom subcategorieen.bron toegevoegd.';
} else {
    $steps[] = 'Kolom subcategorieen.bron al aanwezig (overgeslagen).';
}
if (!_col_exists($pdo, $dbn, 'subcategorieen', 'description')) {
    $pdo->exec(
        "ALTER TABLE subcategorieen
         ADD COLUMN `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL
         AFTER `bron`"
    );
    $steps[] = 'Kolom subcategorieen.description toegevoegd.';
} else {
    $steps[] = 'Kolom subcategorieen.description al aanwezig (overgeslagen).';
}

// 4. ENUM-uitbreiding: scoring_rondes.scope en traject_deelnemer_scopes.scope
//    moeten 'IMPL' kennen voordat we daadwerkelijk IMPL-rondes/scopes toelaten.
function _enum_has(PDO $pdo, string $db, string $table, string $col, string $value): bool {
    $st = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $st->execute([':s' => $db, ':t' => $table, ':c' => $col]);
    $type = (string)$st->fetchColumn();
    return strpos($type, "'$value'") !== false;
}

if (!_enum_has($pdo, $dbn, 'scoring_rondes', 'scope', 'IMPL')) {
    $pdo->exec(
        "ALTER TABLE scoring_rondes
         MODIFY COLUMN `scope`
         enum('FUNC','NFR','VEND','IMPL','LIC','SUP','DEMO')
         COLLATE utf8mb4_unicode_ci NOT NULL"
    );
    $steps[] = 'ENUM scoring_rondes.scope uitgebreid met IMPL.';
} else {
    $steps[] = 'ENUM scoring_rondes.scope bevat IMPL al (overgeslagen).';
}

if (!_enum_has($pdo, $dbn, 'traject_deelnemer_scopes', 'scope', 'IMPL')) {
    $pdo->exec(
        "ALTER TABLE traject_deelnemer_scopes
         MODIFY COLUMN `scope`
         enum('FUNC','NFR','VEND','IMPL','LIC','SUP')
         COLLATE utf8mb4_unicode_ci NOT NULL"
    );
    $steps[] = 'ENUM traject_deelnemer_scopes.scope uitgebreid met IMPL.';
} else {
    $steps[] = 'ENUM traject_deelnemer_scopes.scope bevat IMPL al (overgeslagen).';
}

// 5. IMPL-categorie
$implExists = (int)db_value('SELECT COUNT(*) FROM categorieen WHERE code = :c', [':c' => 'IMPL']);
if (!$implExists) {
    db_insert('categorieen', [
        'code'       => 'IMPL',
        'name'       => 'Implementatie',
        'type'       => 'other',
        'sort_order' => 35,
    ]);
    $steps[] = 'Hoofdcategorie IMPL (Implementatie) toegevoegd.';
} else {
    $steps[] = 'Hoofdcategorie IMPL al aanwezig (overgeslagen).';
}

// 6. Backfill: zorg dat ieder bestaand traject een weight-rij heeft voor IMPL
//    (anders verschijnt IMPL niet in de weging-tab van oudere trajecten).
$implCatId = (int)db_value('SELECT id FROM categorieen WHERE code = :c', [':c' => 'IMPL']);
if ($implCatId) {
    $missing = db_all(
        'SELECT t.id FROM trajecten t
          WHERE NOT EXISTS (
                SELECT 1 FROM weights w
                 WHERE w.traject_id = t.id
                   AND w.categorie_id = :c
                   AND w.subcategorie_id IS NULL
          )',
        [':c' => $implCatId]
    );
    if ($missing) {
        foreach ($missing as $r) {
            db_insert('weights', [
                'traject_id'      => (int)$r['id'],
                'categorie_id'    => $implCatId,
                'subcategorie_id' => null,
                'weight'          => 0,
            ]);
        }
        $steps[] = 'IMPL-weight-rij toegevoegd voor ' . count($missing) . ' bestaand(e) traject(en) (gewicht 0 — pas aan in Weging).';
    } else {
        $steps[] = 'Alle trajecten hebben al een IMPL-weight-rij (overgeslagen).';
    }
}

echo "Migratie voltooid:\n";
foreach ($steps as $s) echo "  - $s\n";

audit_log('migrate_add_impl_bron', 'schema', 0, implode(' | ', $steps));
