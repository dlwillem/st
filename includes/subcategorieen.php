<?php
/**
 * CRUD voor subcategorieën — per traject, per hoofdcategorie.
 * Alle hoofdcategorieën (FUNC/NFR/VEND/IMPL/SUP/LIC) zijn via deze helpers
 * bewerkbaar. Bij elke wijziging worden de gewichten binnen de hoofdcategorie
 * gelijkmatig herverdeeld.
 */

if (!defined('APP_BOOT')) { http_response_code(403); exit('Forbidden'); }

function subcat_find(int $subId, int $trajectId): ?array {
    $row = db_one(
        'SELECT s.*, c.code AS cat_code, c.name AS cat_name
           FROM subcategorieen s
           JOIN categorieen c ON c.id = s.categorie_id
          WHERE s.id = :id AND s.traject_id = :t',
        [':id' => $subId, ':t' => $trajectId]
    );
    return $row ?: null;
}

function subcat_create(int $trajectId, int $catId, string $name, ?string $bron = null, ?string $description = null, ?int $applicatiesoortId = null): int {
    $name = trim($name);
    if ($name === '') throw new RuntimeException('Naam is verplicht.');
    $catExists = db_value('SELECT id FROM categorieen WHERE id = :c', [':c' => $catId]);
    if (!$catExists) throw new RuntimeException('Onbekende hoofdcategorie.');

    $maxOrder = (int)db_value(
        'SELECT COALESCE(MAX(sort_order),0) FROM subcategorieen
          WHERE categorie_id = :c AND traject_id = :t',
        [':c' => $catId, ':t' => $trajectId]
    );
    $id = db_insert('subcategorieen', [
        'categorie_id'       => $catId,
        'traject_id'         => $trajectId,
        'applicatiesoort_id' => $applicatiesoortId ?: null,
        'name'               => $name,
        'bron'               => ($bron !== null && $bron !== '') ? $bron : null,
        'description'        => ($description !== null && $description !== '') ? $description : null,
        'sort_order'         => $maxOrder + 10,
    ]);
    subcat_rebalance_weights($trajectId, $catId);
    audit_log('subcat_created', 'subcategorie', $id, $name);
    return $id;
}

function subcat_rename(int $subId, int $trajectId, string $name): void {
    subcat_update($subId, $trajectId, ['name' => $name]);
}

/**
 * Werk een subcategorie van een traject bij. $patch mag bevatten:
 *   name, bron, description, applicatiesoort_id.
 */
function subcat_update(int $subId, int $trajectId, array $patch): void {
    $sub = subcat_find($subId, $trajectId);
    if (!$sub) return;
    $allowed = ['name', 'bron', 'description', 'applicatiesoort_id'];
    $data = [];
    foreach ($allowed as $k) {
        if (!array_key_exists($k, $patch)) continue;
        $v = $patch[$k];
        if ($k === 'name') {
            $v = trim((string)$v);
            if ($v === '') throw new RuntimeException('Naam is verplicht.');
        } elseif ($k === 'applicatiesoort_id') {
            $v = $v ? (int)$v : null;
        } else {
            $v = (is_string($v) && $v !== '') ? $v : null;
        }
        $data[$k] = $v;
    }
    if (!$data) return;
    db_update('subcategorieen', $data, 'id = :id', [':id' => $subId]);
    audit_log('subcat_updated', 'subcategorie', $subId, (string)($data['name'] ?? $sub['name']));
}

function subcat_delete(int $subId, int $trajectId): void {
    $sub = subcat_find($subId, $trajectId);
    if (!$sub) return;

    $reqCount = (int)db_value(
        'SELECT COUNT(*) FROM requirements WHERE subcategorie_id = :s',
        [':s' => $subId]
    );
    if ($reqCount > 0) {
        throw new RuntimeException(
            "Deze subcategorie heeft $reqCount requirement(s). Verplaats of verwijder die eerst."
        );
    }

    db_exec('DELETE FROM subcategorieen WHERE id = :id', [':id' => $subId]);
    subcat_rebalance_weights($trajectId, (int)$sub['categorie_id']);
    audit_log('subcat_deleted', 'subcategorie', $subId, (string)$sub['name']);
}

/**
 * Herverdeel subcat-gewichten binnen één hoofdcategorie gelijkmatig (som = 100).
 */
function subcat_rebalance_weights(int $trajectId, int $catId): void {
    $ids = db_all(
        'SELECT id FROM subcategorieen
          WHERE categorie_id = :c AND traject_id = :t
          ORDER BY sort_order, id',
        [':c' => $catId, ':t' => $trajectId]
    );
    if (!$ids) return;
    $map = [];
    foreach ($ids as $r) $map[(int)$r['id']] = 1.0;
    $normalized = weights_normalize_group($map);
    foreach ($normalized as $sid => $w) {
        weights_upsert_sub($trajectId, (int)$sid, (float)$w);
    }
}
