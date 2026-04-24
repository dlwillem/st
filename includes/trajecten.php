<?php
/**
 * Traject-logica: aanmaken (met kopieer-wizard), status, huidige selectie.
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

const TRAJECT_STATUSES = ['concept', 'actief', 'afgerond', 'gearchiveerd'];

// ─── Categorie-structuur ─────────────────────────────────────────────────────
//   FUNC → Functionele requirements
//   NFR  → Non functionele requirements
//   VEND → Leverancier
//   LIC  → Licentiemodel
//   SUP  → Support
//
// Subcategorieën zijn altijd per traject; ze worden bij traject_create()
// gekopieerd uit subcategorie_templates (per hoofdcategorie).

// ─── Huidige traject (sessie-context) ────────────────────────────────────────

function current_traject_id(): ?int {
    return isset($_SESSION['traject_id']) ? (int)$_SESSION['traject_id'] : null;
}

function set_current_traject(int $id): void {
    $_SESSION['traject_id'] = $id;
}

function clear_current_traject(): void {
    unset($_SESSION['traject_id']);
}

function current_traject(): ?array {
    $id = current_traject_id();
    if (!$id) return null;
    return db_one('SELECT * FROM trajecten WHERE id = :id', [':id' => $id]);
}

// ─── CRUD ────────────────────────────────────────────────────────────────────

/**
 * Maakt een nieuw traject aan, kopieert FUNC-subcats vanuit templates en
 * initialiseert gewichten met gelijkmatige verdeling (hoofd- én sub-niveau).
 * Retourneert het nieuwe traject-id.
 */
function traject_create(
    string $name,
    string $description,
    ?string $startDate,
    ?string $endDate,
    string $status = 'concept',
    array $applicatiesoortIds = [],
    array $flatTemplateIds = []
): int {
    require_once __DIR__ . '/applicatiesoorten.php';
    $user = current_user();

    return db_transaction(function () use ($name, $description, $startDate, $endDate, $status, $user, $applicatiesoortIds, $flatTemplateIds) {
        $id = db_insert('trajecten', [
            'name'        => $name,
            'description' => $description !== '' ? $description : null,
            'status'      => $status,
            'start_date'  => $startDate ?: null,
            'end_date'    => $endDate   ?: null,
            'created_by'  => $user['id'] ?? null,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        // FUNC: subcats uit de geselecteerde applicatiesoorten
        if ($applicatiesoortIds) {
            applicatiesoorten_copy_to_traject($id, $applicatiesoortIds);
        }

        // NFR/VEND/LIC/SUP: individueel gekozen templates
        if ($flatTemplateIds) {
            templates_copy_to_traject($id, $flatTemplateIds);
        }

        traject_init_weights($id);

        audit_log('traject_created', 'traject', $id, $name);
        return $id;
    });
}

/**
 * Gelijkmatige weight-verdeling bij aanmaken:
 *  - op hoofdcategorie-niveau: 100 / aantal hoofdcategorieën
 *  - op subcategorie-niveau (per hoofd): 100 / aantal subcats in die hoofd
 * Alle gewichten zijn "proportioneel" — som binnen elk niveau = 100.
 */
function traject_init_weights(int $trajectId): void {
    $cats = db_all('SELECT id FROM categorieen ORDER BY sort_order, id');
    if (!$cats) return;
    $catWeight = round(100 / count($cats), 3);

    foreach ($cats as $c) {
        db_insert('weights', [
            'traject_id'      => $trajectId,
            'categorie_id'    => (int)$c['id'],
            'subcategorie_id' => null,
            'weight'          => $catWeight,
        ]);

        $subs = db_all(
            'SELECT id FROM subcategorieen
              WHERE categorie_id = :c AND traject_id = :t
              ORDER BY sort_order, id',
            [':c' => (int)$c['id'], ':t' => $trajectId]
        );
        if (!$subs) continue;
        $subWeight = round(100 / count($subs), 3);
        foreach ($subs as $s) {
            db_insert('weights', [
                'traject_id'      => $trajectId,
                'categorie_id'    => null,
                'subcategorie_id' => (int)$s['id'],
                'weight'          => $subWeight,
            ]);
        }
    }
}

function traject_update(int $id, array $data): void {
    $allowed = ['name','description','status','start_date','end_date'];
    $filtered = array_intersect_key($data, array_flip($allowed));
    if (!$filtered) return;
    $filtered['updated_at'] = date('Y-m-d H:i:s');
    db_update('trajecten', $filtered, 'id = :id', [':id' => $id]);
    audit_log('traject_updated', 'traject', $id, $data['name'] ?? '');
}

function traject_set_status(int $id, string $status): void {
    if (!in_array($status, TRAJECT_STATUSES, true)) return;
    db_update('trajecten',
        ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')],
        'id = :id', [':id' => $id]);
    audit_log('traject_status_changed', 'traject', $id, $status);
}

function traject_delete(int $id): void {
    $name = (string)db_value('SELECT name FROM trajecten WHERE id = :id', [':id' => $id]);
    db_exec('DELETE FROM trajecten WHERE id = :id', [':id' => $id]);
    if (current_traject_id() === $id) clear_current_traject();
    audit_log('traject_deleted', 'traject', $id, $name);
}

// ─── Stats per traject (voor lijst) ──────────────────────────────────────────
function traject_stats(int $id): array {
    $row = db_one(
        'SELECT
            (SELECT COUNT(*) FROM requirements   WHERE traject_id = :t1) AS requirements,
            (SELECT COUNT(*) FROM leveranciers   WHERE traject_id = :t2) AS leveranciers,
            (SELECT COUNT(*) FROM scoring_rondes WHERE traject_id = :t3) AS rondes',
        [':t1' => $id, ':t2' => $id, ':t3' => $id]
    );
    return $row ?: ['requirements' => 0, 'leveranciers' => 0, 'rondes' => 0];
}

// ─── Label/kleur helpers ─────────────────────────────────────────────────────
function traject_status_badge(string $status): string {
    $map = [
        'concept'        => ['gray',   'Concept'],
        'actief'         => ['green',  'Actief'],
        'afgerond'       => ['indigo', 'Afgerond'],
        'gearchiveerd'   => ['amber',  'Gearchiveerd'],
    ];
    [$color, $label] = $map[$status] ?? ['gray', $status];
    return '<span class="badge ' . $color . '">' . h($label) . '</span>';
}
