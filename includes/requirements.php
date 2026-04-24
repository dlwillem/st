<?php
/**
 * Requirements — CRUD per traject.
 *
 * Elk requirement hangt aan een subcategorie (per traject). Code wordt
 * automatisch gegenereerd per traject-per-hoofdcategorie:
 *   FUNC → FR-001, NFR → NFR-001, VEND → VEND-001, LIC → LIC-001, SUP → SUP-001
 * Type: 'eis' | 'wens' | 'ko' (knock-out).
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

const REQUIREMENT_TYPES = ['eis', 'wens', 'ko'];

// Hoofdcategorie-code → code-prefix voor requirements
const REQUIREMENT_CODE_PREFIX = [
    'FUNC' => 'FR',
    'NFR'  => 'NFR',
    'VEND' => 'VEND',
    'LIC'  => 'LIC',
    'SUP'  => 'SUP',
];

function requirement_prefix_for_cat_code(string $code): string {
    return REQUIREMENT_CODE_PREFIX[$code] ?? 'REQ';
}

// Icoon + kleurklasse per hoofdcategorie (voor list-kaarten).
const REQUIREMENT_CAT_STYLE = [
    'FUNC' => ['icon' => 'clipboard', 'color' => 'indigo'],
    'NFR'  => ['icon' => 'sliders',   'color' => 'amber'],
    'VEND' => ['icon' => 'package',   'color' => 'green'],
    'LIC'  => ['icon' => 'file-text', 'color' => 'red'],
    'SUP'  => ['icon' => 'bell',      'color' => 'gray'],
];

function requirement_cat_style(string $code): array {
    return REQUIREMENT_CAT_STYLE[$code] ?? ['icon' => 'folder', 'color' => 'gray'];
}

function requirement_type_label(string $t): string {
    return [
        'eis'  => 'Must',
        'wens' => 'Should',
        'ko'   => 'Knock-out',
    ][$t] ?? $t;
}

function requirement_type_badge(string $t): string {
    $map = [
        'eis'  => ['indigo', 'Must'],
        'wens' => ['blue',   'Should'],
        'ko'   => ['red',    'Knock-out'],
    ];
    [$color, $label] = $map[$t] ?? ['gray', $t];
    return '<span class="badge ' . $color . '">' . h($label) . '</span>';
}

/**
 * Genereert een unieke code binnen het traject, per hoofdcategorie.
 * Prefix = REQUIREMENT_CODE_PREFIX[cat_code].  Bijv. FR-001, NFR-007.
 */
function requirement_next_code(int $trajectId, int $subId): string {
    $catCode = (string)db_value(
        'SELECT c.code
           FROM subcategorieen s
           JOIN categorieen c ON c.id = s.categorie_id
          WHERE s.id = :s',
        [':s' => $subId]
    );
    $prefix = requirement_prefix_for_cat_code($catCode);
    $like   = $prefix . '-%';
    $max    = (int)db_value(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(r.code, :plen) AS UNSIGNED)), 0)
           FROM requirements r
          WHERE r.traject_id = :t AND r.code LIKE :like",
        [':t' => $trajectId, ':like' => $like, ':plen' => strlen($prefix) + 2]
    );
    return sprintf('%s-%03d', $prefix, $max + 1);
}

/**
 * Controleert of subcategorie hoort bij dit traject.
 */
function requirement_subcat_allowed(int $subId, int $trajectId): bool {
    $row = db_one(
        'SELECT traject_id FROM subcategorieen WHERE id = :id',
        [':id' => $subId]
    );
    if (!$row) return false;
    return (int)$row['traject_id'] === $trajectId;
}

function requirement_create(int $trajectId, array $data): int {
    if (!requirement_subcat_allowed((int)$data['subcategorie_id'], $trajectId)) {
        throw new RuntimeException('Ongeldige subcategorie voor dit traject.');
    }
    if (!in_array($data['type'] ?? 'eis', REQUIREMENT_TYPES, true)) {
        throw new RuntimeException('Ongeldig type.');
    }
    $code = requirement_next_code($trajectId, (int)$data['subcategorie_id']);

    $maxOrder = (int)db_value(
        'SELECT COALESCE(MAX(sort_order),0) FROM requirements
          WHERE traject_id = :t AND subcategorie_id = :s',
        [':t' => $trajectId, ':s' => (int)$data['subcategorie_id']]
    );

    $id = db_insert('requirements', [
        'traject_id'      => $trajectId,
        'subcategorie_id' => (int)$data['subcategorie_id'],
        'code'            => $code,
        'title'           => (string)$data['title'],
        'description'     => !empty($data['description']) ? (string)$data['description'] : null,
        'type'            => (string)$data['type'],
        'sort_order'      => $maxOrder + 10,
        'created_at'      => date('Y-m-d H:i:s'),
        'updated_at'      => date('Y-m-d H:i:s'),
    ]);
    audit_log('requirement_created', 'requirement', $id, $code . ' — ' . $data['title']);
    return $id;
}

function requirement_update(int $id, int $trajectId, array $data): void {
    $existing = db_one(
        'SELECT * FROM requirements WHERE id = :id AND traject_id = :t',
        [':id' => $id, ':t' => $trajectId]
    );
    if (!$existing) throw new RuntimeException('Requirement niet gevonden.');

    if (isset($data['subcategorie_id'])
        && !requirement_subcat_allowed((int)$data['subcategorie_id'], $trajectId)) {
        throw new RuntimeException('Ongeldige subcategorie.');
    }
    if (isset($data['type']) && !in_array($data['type'], REQUIREMENT_TYPES, true)) {
        throw new RuntimeException('Ongeldig type.');
    }

    $allowed = ['subcategorie_id','title','description','type'];
    $filtered = array_intersect_key($data, array_flip($allowed));
    if (isset($filtered['description']) && $filtered['description'] === '') {
        $filtered['description'] = null;
    }
    if (!$filtered) return;
    $filtered['updated_at'] = date('Y-m-d H:i:s');
    db_update('requirements', $filtered, 'id = :id', [':id' => $id]);
    audit_log('requirement_updated', 'requirement', $id, (string)($data['title'] ?? $existing['title']));
}

function requirement_delete(int $id, int $trajectId): void {
    $row = db_one(
        'SELECT code, title FROM requirements WHERE id = :id AND traject_id = :t',
        [':id' => $id, ':t' => $trajectId]
    );
    if (!$row) return;
    db_exec('DELETE FROM requirements WHERE id = :id', [':id' => $id]);
    audit_log('requirement_deleted', 'requirement', $id, $row['code'] . ' — ' . $row['title']);
}

/**
 * Haalt alle bruikbare subcategorieën voor een traject op, gegroepeerd per
 * hoofdcategorie. Gebruikt voor dropdowns en filters.
 */
function requirement_subcats_for_traject(int $trajectId): array {
    return db_all(
        'SELECT s.id, s.name, s.sort_order,
                c.id AS cat_id, c.code AS cat_code, c.name AS cat_name, c.sort_order AS cat_order
           FROM subcategorieen s
           JOIN categorieen c ON c.id = s.categorie_id
          WHERE s.traject_id = :t
          ORDER BY c.sort_order, s.sort_order, s.id',
        [':t' => $trajectId]
    );
}

/**
 * Haal requirements + subcategorie/categorie-info op, met optionele filters.
 * Filters: type, subcategorie_id, cat_code, query (in code/title/description).
 */
function requirements_list(int $trajectId, array $filters = []): array {
    $sql = 'SELECT r.*, s.name AS sub_name, c.code AS cat_code, c.name AS cat_name,
                   a.label AS app_label, a.description AS app_description
              FROM requirements r
              JOIN subcategorieen s ON s.id = r.subcategorie_id
              JOIN categorieen    c ON c.id = s.categorie_id
              LEFT JOIN applicatiesoorten a ON a.id = s.applicatiesoort_id
             WHERE r.traject_id = :t';
    $params = [':t' => $trajectId];

    if (!empty($filters['type']) && in_array($filters['type'], REQUIREMENT_TYPES, true)) {
        $sql .= ' AND r.type = :type';
        $params[':type'] = $filters['type'];
    }
    if (!empty($filters['subcategorie_id'])) {
        $sql .= ' AND r.subcategorie_id = :sid';
        $params[':sid'] = (int)$filters['subcategorie_id'];
    }
    if (!empty($filters['cat_code'])) {
        $sql .= ' AND c.code = :cc';
        $params[':cc'] = $filters['cat_code'];
    }
    if (!empty($filters['q'])) {
        $sql .= ' AND (r.code LIKE :q1 OR r.title LIKE :q2 OR r.description LIKE :q3)';
        $like = '%' . $filters['q'] . '%';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
    }

    $sql .= ' ORDER BY c.sort_order, s.sort_order, r.sort_order, r.id';
    return db_all($sql, $params);
}

/**
 * Is er voor dit traject ooit een scoringsronde geopend of gesloten?
 * Als dat zo is, zijn bulk-mutaties (upload) geblokkeerd.
 */
function requirements_scoring_locked(int $trajectId): bool {
    $n = (int)db_value(
        "SELECT COUNT(*) FROM scoring_rondes
          WHERE traject_id = :t AND status IN ('open','gesloten')",
        [':t' => $trajectId]
    );
    return $n > 0;
}

/**
 * Zoek duplicaten en bijna-duplicaten binnen dit traject.
 * - exact  : genormaliseerde titel is identiek
 * - fuzzy  : similar_text ≥ 80%  OF  Levenshtein-ratio ≥ 80%
 *
 * Retourneert lijst met groepen:
 *   [ ['kind' => 'exact'|'fuzzy', 'reqs' => [requirement_row, ...]], ... ]
 */
function requirements_find_duplicates(int $trajectId): array {
    $rows = db_all(
        'SELECT r.id, r.code, r.title, r.type, r.subcategorie_id,
                s.name AS sub_name, c.code AS cat_code, c.name AS cat_name
           FROM requirements r
           JOIN subcategorieen s ON s.id = r.subcategorie_id
           JOIN categorieen    c ON c.id = s.categorie_id
          WHERE r.traject_id = :t
          ORDER BY r.id',
        [':t' => $trajectId]
    );
    if (count($rows) < 2) return [];

    $norm = static function (string $s): string {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\s+/', ' ', $s);
        return (string)$s;
    };

    $groups = [];

    // Exact
    $byNorm = [];
    foreach ($rows as $r) $byNorm[$norm($r['title'])][] = $r;
    foreach ($byNorm as $set) {
        if (count($set) > 1) $groups[] = ['kind' => 'exact', 'reqs' => $set];
    }

    // Fuzzy (paarsgewijs, exclusief exacte overlap)
    $exactIds = [];
    foreach ($groups as $g) foreach ($g['reqs'] as $r) $exactIds[(int)$r['id']] = true;

    $pairs = [];
    for ($i = 0, $n = count($rows); $i < $n; $i++) {
        if (isset($exactIds[(int)$rows[$i]['id']])) continue;
        $a = $norm($rows[$i]['title']);
        if ($a === '') continue;
        for ($j = $i + 1; $j < $n; $j++) {
            if (isset($exactIds[(int)$rows[$j]['id']])) continue;
            $b = $norm($rows[$j]['title']);
            if ($b === '') continue;
            similar_text($a, $b, $pct);
            $lev = levenshtein($a, $b);
            $maxLen = max(mb_strlen($a), mb_strlen($b));
            $levRatio = $maxLen > 0 ? (1 - $lev / $maxLen) * 100 : 0;
            if ($pct >= 80 || $levRatio >= 80) {
                $pairs[] = [$rows[$i], $rows[$j]];
            }
        }
    }
    // Clusters vormen via union-find
    if ($pairs) {
        $parent = [];
        $find = function ($x) use (&$parent, &$find) {
            while ($parent[$x] !== $x) $x = $parent[$x] = $parent[$parent[$x]];
            return $x;
        };
        foreach ($pairs as [$a, $b]) {
            $ai = (int)$a['id']; $bi = (int)$b['id'];
            if (!isset($parent[$ai])) $parent[$ai] = $ai;
            if (!isset($parent[$bi])) $parent[$bi] = $bi;
            $ra = $find($ai); $rb = $find($bi);
            if ($ra !== $rb) $parent[$ra] = $rb;
        }
        $clusters = [];
        $byId = []; foreach ($rows as $r) $byId[(int)$r['id']] = $r;
        foreach (array_keys($parent) as $id) {
            $root = $find($id);
            $clusters[$root][] = $byId[$id];
        }
        foreach ($clusters as $c) {
            if (count($c) > 1) $groups[] = ['kind' => 'fuzzy', 'reqs' => $c];
        }
    }
    return $groups;
}
