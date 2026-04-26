<?php
/**
 * Applicatiesoorten — repository-groep voor FUNC subcat-templates.
 * Een applicatiesoort (bv. "L-17 HRM — Human Resource Management") groepeert
 * één of meer FUNC subcategorie-templates die samen gekopieerd worden bij
 * het aanmaken van een traject. NFR/VEND/LIC/SUP/IMPL werken zonder deze
 * tussenlaag — hun templates staan direct in `subcategorie_templates`.
 */

if (!defined('APP_BOOT')) { http_response_code(403); exit('Forbidden'); }

function applicatiesoort_create(string $name, ?string $description = null, ?string $bron = null): int {
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Naam is verplicht.');
    }
    if (mb_strlen($name) > 200) {
        throw new RuntimeException('Naam te lang (max 200 tekens).');
    }
    $exists = db_value('SELECT id FROM applicatiesoorten WHERE name = :n', [':n' => $name]);
    if ($exists) {
        throw new RuntimeException("Applicatiesoort met naam '$name' bestaat al.");
    }
    $id = db_insert('applicatiesoorten', [
        'name'        => $name,
        'description' => ($description !== null && $description !== '') ? $description : null,
        'bron'        => ($bron !== null && $bron !== '') ? $bron : null,
        'sort_order'  => 0,
    ]);
    audit_log('applicatiesoort_created', 'applicatiesoort', $id, $name);
    return $id;
}

function applicatiesoort_update(int $id, string $name, ?string $description, ?string $bron): void {
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Naam is verplicht.');
    }
    if (mb_strlen($name) > 200) {
        throw new RuntimeException('Naam te lang (max 200 tekens).');
    }
    $current = db_one('SELECT id FROM applicatiesoorten WHERE id = :id', [':id' => $id]);
    if (!$current) {
        throw new RuntimeException('Applicatiesoort niet gevonden.');
    }
    $clash = db_value(
        'SELECT id FROM applicatiesoorten WHERE name = :n AND id <> :id',
        [':n' => $name, ':id' => $id]
    );
    if ($clash) {
        throw new RuntimeException("Andere applicatiesoort heeft deze naam al: '$name'.");
    }
    db_update('applicatiesoorten', [
        'name'        => $name,
        'description' => ($description !== null && $description !== '') ? $description : null,
        'bron'        => ($bron !== null && $bron !== '') ? $bron : null,
    ], 'id = :id', [':id' => $id]);
    audit_log('applicatiesoort_updated', 'applicatiesoort', $id, $name);
}

/**
 * Aantal gekoppelde FUNC-templates en traject-instances voor een applicatiesoort.
 */
function applicatiesoort_usage(int $id): array {
    $tpls = (int)db_value(
        'SELECT COUNT(*) FROM subcategorie_templates WHERE applicatiesoort_id = :id',
        [':id' => $id]
    );
    $subs = (int)db_value(
        'SELECT COUNT(*) FROM subcategorieen WHERE applicatiesoort_id = :id',
        [':id' => $id]
    );
    return ['templates' => $tpls, 'instances' => $subs];
}

function applicatiesoort_delete(int $id): void {
    $row = db_one('SELECT id, name FROM applicatiesoorten WHERE id = :id', [':id' => $id]);
    if (!$row) {
        throw new RuntimeException('Applicatiesoort niet gevonden.');
    }
    $use = applicatiesoort_usage($id);
    if ($use['templates'] > 0 || $use['instances'] > 0) {
        throw new RuntimeException(sprintf(
            "Kan '%s' niet verwijderen: nog %d app-service(s) en %d traject-koppeling(en). "
            . "Verplaats of verwijder die eerst.",
            $row['name'], $use['templates'], $use['instances']
        ));
    }
    db_exec('DELETE FROM applicatiesoorten WHERE id = :id', [':id' => $id]);
    audit_log('applicatiesoort_deleted', 'applicatiesoort', $id, (string)$row['name']);
}

/**
 * Lijst alle applicatiesoorten met usage-counts voor de beheer-UI.
 * Sortering: alfabetisch op naam.
 */
function applicatiesoorten_with_usage(): array {
    return db_all(
        "SELECT a.id, a.name, a.description, a.bron,
                (SELECT COUNT(*) FROM subcategorie_templates WHERE applicatiesoort_id = a.id) AS templates,
                (SELECT COUNT(*) FROM subcategorieen         WHERE applicatiesoort_id = a.id) AS instances
           FROM applicatiesoorten a
          ORDER BY a.name"
    );
}

/**
 * Kopieer de FUNC-templates van de geselecteerde applicatiesoorten naar
 * `subcategorieen` voor het opgegeven traject.
 */
function applicatiesoorten_copy_to_traject(int $trajectId, array $applicatiesoortIds): int {
    $applicatiesoortIds = array_values(array_unique(array_map('intval', $applicatiesoortIds)));
    if (!$applicatiesoortIds) return 0;

    $funcId = (int)db_value('SELECT id FROM categorieen WHERE code = :c', [':c' => 'FUNC']);
    if (!$funcId) return 0;

    $in = implode(',', array_fill(0, count($applicatiesoortIds), '?'));
    $tpls = db_all(
        "SELECT id, applicatiesoort_id, name, bron, description, sort_order
           FROM subcategorie_templates
          WHERE categorie_id = $funcId AND applicatiesoort_id IN ($in)
          ORDER BY applicatiesoort_id, name, id",
        $applicatiesoortIds
    );
    $n = 0;
    foreach ($tpls as $t) {
        db_insert('subcategorieen', [
            'categorie_id'       => $funcId,
            'traject_id'         => $trajectId,
            'applicatiesoort_id' => (int)$t['applicatiesoort_id'],
            'name'               => $t['name'],
            'bron'               => $t['bron'] ?? null,
            'description'        => $t['description'] ?? null,
            'sort_order'         => (int)$t['sort_order'],
        ]);
        $n++;
    }
    return $n;
}

/**
 * Kopieer specifieke subcategorie-templates naar een traject. Gebruikt door
 * de wizard voor NFR/VEND/LIC/SUP/IMPL-keuzes (daar kiest de gebruiker
 * individuele templates in plaats van een groep).
 */
function templates_copy_to_traject(int $trajectId, array $templateIds): int {
    $templateIds = array_values(array_unique(array_map('intval', $templateIds)));
    if (!$templateIds) return 0;
    $in = implode(',', array_fill(0, count($templateIds), '?'));
    $tpls = db_all(
        "SELECT id, categorie_id, applicatiesoort_id, name, bron, description, sort_order
           FROM subcategorie_templates
          WHERE id IN ($in)
          ORDER BY categorie_id, name, id",
        $templateIds
    );
    $n = 0;
    foreach ($tpls as $t) {
        db_insert('subcategorieen', [
            'categorie_id'       => (int)$t['categorie_id'],
            'traject_id'         => $trajectId,
            'applicatiesoort_id' => $t['applicatiesoort_id'] ? (int)$t['applicatiesoort_id'] : null,
            'name'               => $t['name'],
            'bron'               => $t['bron'] ?? null,
            'description'        => $t['description'] ?? null,
            'sort_order'         => (int)$t['sort_order'],
        ]);
        $n++;
    }
    return $n;
}

/**
 * Extraheer de prefix ("L-14", "ATL-1", "PF-MB") uit een naam.
 */
function applicatiesoort_prefix_from(string $name): ?string {
    $name = trim($name);
    if (preg_match('/^((?:L|ATL|DPL|MOL)-\d+)(?:[.\s]|$)/u', $name, $m)) return $m[1];
    if (preg_match('/^(PF-[A-Z]+)(?:[-\s]|$)/u', $name, $m)) return $m[1];
    return null;
}

/**
 * Vul `applicatiesoort_id` in voor bestaande FUNC `subcategorieen`-rijen
 * o.b.v. naam-prefix. Match tegen naam-prefix van de applicatiesoorten.
 */
function applicatiesoorten_autolink_existing(): int {
    $rows = db_all(
        "SELECT s.id, s.name
           FROM subcategorieen s
           JOIN categorieen c ON c.id = s.categorie_id
          WHERE c.code = 'FUNC' AND s.applicatiesoort_id IS NULL"
    );
    if (!$rows) return 0;
    $apps = db_all('SELECT id, name FROM applicatiesoorten');
    $byPrefix = [];
    foreach ($apps as $a) {
        $pfx = applicatiesoort_prefix_from((string)$a['name']);
        if ($pfx !== null) $byPrefix[$pfx] = (int)$a['id'];
    }
    $n = 0;
    foreach ($rows as $r) {
        $pfx = applicatiesoort_prefix_from((string)$r['name']);
        if ($pfx !== null && isset($byPrefix[$pfx])) {
            db_exec(
                'UPDATE subcategorieen SET applicatiesoort_id = :a WHERE id = :id',
                [':a' => $byPrefix[$pfx], ':id' => (int)$r['id']]
            );
            $n++;
        }
    }
    return $n;
}

/**
 * Idem voor losse FUNC-templates zonder applicatiesoort_id.
 */
function applicatiesoorten_autolink_templates(): int {
    $rows = db_all(
        "SELECT t.id, t.name
           FROM subcategorie_templates t
           JOIN categorieen c ON c.id = t.categorie_id
          WHERE c.code = 'FUNC' AND t.applicatiesoort_id IS NULL"
    );
    if (!$rows) return 0;
    $apps = db_all('SELECT id, name FROM applicatiesoorten');
    $byPrefix = [];
    foreach ($apps as $a) {
        $pfx = applicatiesoort_prefix_from((string)$a['name']);
        if ($pfx !== null) $byPrefix[$pfx] = (int)$a['id'];
    }
    $n = 0;
    foreach ($rows as $r) {
        $pfx = applicatiesoort_prefix_from((string)$r['name']);
        if ($pfx !== null && isset($byPrefix[$pfx])) {
            db_exec(
                'UPDATE subcategorie_templates SET applicatiesoort_id = :a WHERE id = :id',
                [':a' => $byPrefix[$pfx], ':id' => (int)$r['id']]
            );
            $n++;
        }
    }
    return $n;
}

/**
 * Seed de applicatiesoort-repository vanuit /data/applicatiesoorten_seed.php.
 * Idempotent: matcht op naam-prefix (bv. "L-10") om duplicaten te voorkomen.
 *
 * Het seed-bestand gebruikt de sleutel `label`, die hier als `name` wordt
 * weggeschreven (DB-rename).
 */
function applicatiesoorten_seed_from_file(string $seedFile): array {
    if (!is_file($seedFile)) {
        throw new RuntimeException('Seedbestand niet gevonden: ' . $seedFile);
    }
    $data = require $seedFile;
    if (!is_array($data)) {
        throw new RuntimeException('Seedbestand geeft geen array terug.');
    }

    $funcId = (int)db_value('SELECT id FROM categorieen WHERE code = :c', [':c' => 'FUNC']);
    if (!$funcId) {
        throw new RuntimeException('Hoofdcategorie FUNC ontbreekt.');
    }

    // Bouw prefix-index van bestaande applicatiesoorten
    $existing = db_all('SELECT id, name FROM applicatiesoorten');
    $byPrefix = [];
    foreach ($existing as $e) {
        $pfx = applicatiesoort_prefix_from((string)$e['name']);
        if ($pfx !== null) $byPrefix[$pfx] = (int)$e['id'];
    }

    $appsCreated = 0; $tplsCreated = 0;
    foreach ($data as $app) {
        $code  = (string)($app['code'] ?? '');   // uit seed: "L-10"
        $name  = (string)($app['label'] ?? '');  // uit seed: "L-10 CPQ" — sleutel blijft 'label' in seedbestand
        $desc  = $app['description'] ?? null;
        $bron  = $app['bron'] ?? null;

        $pfx = $code !== '' ? $code : applicatiesoort_prefix_from($name);
        $appId = ($pfx !== null && isset($byPrefix[$pfx])) ? $byPrefix[$pfx] : 0;
        if (!$appId) {
            $appId = db_insert('applicatiesoorten', [
                'name'        => $name !== '' ? $name : $code,
                'description' => $desc,
                'bron'        => $bron,
                'sort_order'  => 0,
            ]);
            if ($pfx !== null) $byPrefix[$pfx] = $appId;
            $appsCreated++;
        }

        foreach ($app['subs'] ?? [] as $sub) {
            $subName = (string)$sub['name'];
            $exists = db_value(
                'SELECT id FROM subcategorie_templates
                  WHERE categorie_id = :c AND applicatiesoort_id = :a AND name = :n',
                [':c' => $funcId, ':a' => $appId, ':n' => $subName]
            );
            if (!$exists) {
                db_insert('subcategorie_templates', [
                    'categorie_id'       => $funcId,
                    'applicatiesoort_id' => $appId,
                    'name'               => $subName,
                    'sort_order'         => 0,
                ]);
                $tplsCreated++;
            }
        }
    }
    return ['apps_created' => $appsCreated, 'templates_created' => $tplsCreated];
}

/**
 * Seed platte subcategorie-templates (NFR/VEND/LIC/SUP/IMPL) voor een hoofdcat.
 * Idempotent op (categorie_id, name).
 */
function cat_templates_seed(string $catCode, array $names): int {
    $catId = (int)db_value('SELECT id FROM categorieen WHERE code = :c', [':c' => $catCode]);
    if (!$catId) return 0;
    $n = 0;
    foreach ($names as $name) {
        $name = trim($name);
        if ($name === '') continue;
        $exists = db_value(
            'SELECT id FROM subcategorie_templates WHERE categorie_id = :c AND name = :n',
            [':c' => $catId, ':n' => $name]
        );
        if (!$exists) {
            db_insert('subcategorie_templates', [
                'categorie_id'       => $catId,
                'applicatiesoort_id' => null,
                'name'               => $name,
                'sort_order'         => 0,
            ]);
            $n++;
        }
    }
    return $n;
}
