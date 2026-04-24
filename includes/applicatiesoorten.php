<?php
/**
 * Applicatiesoorten — repository-groep voor FUNC subcat-templates.
 * Een applicatiesoort (bv. "L-17 HRM — Human Resource Management") groepeert
 * één of meer FUNC subcategorie-templates die samen gekopieerd worden bij
 * het aanmaken van een traject. NFR/VEND/LIC/SUP werken zonder deze
 * tussenlaag — hun templates staan direct in `subcategorie_templates`.
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

function applicatiesoort_create(string $label, ?string $description = null, int $sortOrder = 0): int {
    $label = trim($label);
    if ($label === '') {
        throw new RuntimeException('Label is verplicht.');
    }
    $id = db_insert('applicatiesoorten', [
        'label'       => $label,
        'description' => ($description !== null && $description !== '') ? $description : null,
        'sort_order'  => $sortOrder,
    ]);
    audit_log('applicatiesoort_created', 'applicatiesoort', $id, $label);
    return $id;
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
        "SELECT id, applicatiesoort_id, name, sort_order
           FROM subcategorie_templates
          WHERE categorie_id = $funcId AND applicatiesoort_id IN ($in)
          ORDER BY applicatiesoort_id, sort_order, id",
        $applicatiesoortIds
    );
    $n = 0;
    foreach ($tpls as $t) {
        db_insert('subcategorieen', [
            'categorie_id'       => $funcId,
            'traject_id'         => $trajectId,
            'applicatiesoort_id' => (int)$t['applicatiesoort_id'],
            'name'               => $t['name'],
            'sort_order'         => (int)$t['sort_order'],
        ]);
        $n++;
    }
    return $n;
}

/**
 * Kopieer specifieke subcategorie-templates naar een traject. Gebruikt door
 * de wizard voor NFR/VEND/LIC/SUP-keuzes (daar kiest de gebruiker individuele
 * templates in plaats van een groep).
 */
function templates_copy_to_traject(int $trajectId, array $templateIds): int {
    $templateIds = array_values(array_unique(array_map('intval', $templateIds)));
    if (!$templateIds) return 0;
    $in = implode(',', array_fill(0, count($templateIds), '?'));
    $tpls = db_all(
        "SELECT id, categorie_id, applicatiesoort_id, name, sort_order
           FROM subcategorie_templates
          WHERE id IN ($in)
          ORDER BY categorie_id, sort_order, id",
        $templateIds
    );
    $n = 0;
    foreach ($tpls as $t) {
        db_insert('subcategorieen', [
            'categorie_id'       => (int)$t['categorie_id'],
            'traject_id'         => $trajectId,
            'applicatiesoort_id' => $t['applicatiesoort_id'] ? (int)$t['applicatiesoort_id'] : null,
            'name'               => $t['name'],
            'sort_order'         => (int)$t['sort_order'],
        ]);
        $n++;
    }
    return $n;
}

/**
 * Extraheer de prefix ("L-14", "ATL-1", "PF-MB") uit een naam/label.
 */
function applicatiesoort_prefix_from(string $name): ?string {
    $name = trim($name);
    if (preg_match('/^((?:L|ATL|DPL|MOL)-\d+)(?:[.\s]|$)/u', $name, $m)) return $m[1];
    if (preg_match('/^(PF-[A-Z]+)(?:[-\s]|$)/u', $name, $m)) return $m[1];
    return null;
}

/**
 * Vul `applicatiesoort_id` in voor bestaande FUNC `subcategorieen`-rijen
 * o.b.v. naam-prefix. Match tegen label-prefix van de applicatiesoorten.
 */
function applicatiesoorten_autolink_existing(): int {
    $rows = db_all(
        "SELECT s.id, s.name
           FROM subcategorieen s
           JOIN categorieen c ON c.id = s.categorie_id
          WHERE c.code = 'FUNC' AND s.applicatiesoort_id IS NULL"
    );
    if (!$rows) return 0;
    $apps = db_all('SELECT id, label FROM applicatiesoorten');
    $byPrefix = [];
    foreach ($apps as $a) {
        $pfx = applicatiesoort_prefix_from((string)$a['label']);
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
    $apps = db_all('SELECT id, label FROM applicatiesoorten');
    $byPrefix = [];
    foreach ($apps as $a) {
        $pfx = applicatiesoort_prefix_from((string)$a['label']);
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
 * Idempotent: matcht op label-prefix (bv. "L-10") om duplicaten te voorkomen.
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
    $existing = db_all('SELECT id, label FROM applicatiesoorten');
    $byPrefix = [];
    foreach ($existing as $e) {
        $pfx = applicatiesoort_prefix_from((string)$e['label']);
        if ($pfx !== null) $byPrefix[$pfx] = (int)$e['id'];
    }

    $appsCreated = 0; $tplsCreated = 0; $sort = 0;
    foreach ($data as $app) {
        $sort += 10;
        $code  = (string)($app['code'] ?? '');   // uit seed: "L-10"
        $label = (string)($app['label'] ?? '');  // uit seed: "L-10 CPQ"
        $desc  = $app['description'] ?? null;

        $pfx = $code !== '' ? $code : applicatiesoort_prefix_from($label);
        $appId = ($pfx !== null && isset($byPrefix[$pfx])) ? $byPrefix[$pfx] : 0;
        if (!$appId) {
            $appId = db_insert('applicatiesoorten', [
                'label'       => $label !== '' ? $label : $code,
                'description' => $desc,
                'sort_order'  => $sort,
            ]);
            if ($pfx !== null) $byPrefix[$pfx] = $appId;
            $appsCreated++;
        }

        $tSort = 0;
        foreach ($app['subs'] ?? [] as $sub) {
            $tSort += 10;
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
                    'sort_order'         => $tSort,
                ]);
                $tplsCreated++;
            }
        }
    }
    return ['apps_created' => $appsCreated, 'templates_created' => $tplsCreated];
}

/**
 * Seed platte subcategorie-templates (NFR/VEND/LIC/SUP) voor een hoofdcat.
 * Idempotent op (categorie_id, name).
 */
function cat_templates_seed(string $catCode, array $names): int {
    $catId = (int)db_value('SELECT id FROM categorieen WHERE code = :c', [':c' => $catCode]);
    if (!$catId) return 0;
    $n = 0; $sort = 0;
    foreach ($names as $name) {
        $sort += 10;
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
                'sort_order'         => $sort,
            ]);
            $n++;
        }
    }
    return $n;
}
