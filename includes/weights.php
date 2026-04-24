<?php
/**
 * Weights-logica: ophalen, wegschrijven, normaliseren.
 *
 * Weights worden opgeslagen per traject, apart voor hoofdcategorieën
 * (categorie_id gevuld) en subcategorieën (subcategorie_id gevuld).
 * Binnen elk niveau wordt proportioneel genormaliseerd zodat de som
 * per "groep" altijd 100 is.
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

/**
 * Lees alle weights voor een traject.
 * Retourneert [
 *   'cats'    => [cat_id => weight, ...],
 *   'subs'    => [sub_id => weight, ...],
 * ]
 */
function weights_load(int $trajectId): array {
    $rows = db_all(
        'SELECT categorie_id, subcategorie_id, weight
           FROM weights
          WHERE traject_id = :t',
        [':t' => $trajectId]
    );
    $out = ['cats' => [], 'subs' => []];
    foreach ($rows as $r) {
        if ($r['categorie_id'] !== null) {
            $out['cats'][(int)$r['categorie_id']] = (float)$r['weight'];
        } elseif ($r['subcategorie_id'] !== null) {
            $out['subs'][(int)$r['subcategorie_id']] = (float)$r['weight'];
        }
    }
    return $out;
}

/**
 * Zet een lijst weights voor een traject. Alle rijen in de input worden
 * genormaliseerd naar een som van 100 per groep.
 *
 * @param int   $trajectId
 * @param array $cats  [cat_id => raw weight]
 * @param array $subs  [subCatId => raw weight]  (geen groepering nodig —
 *                     subcategorieën binnen dezelfde hoofdcategorie worden
 *                     automatisch geïdentificeerd via DB)
 */
function weights_save(int $trajectId, array $cats, array $subs): void {
    db_transaction(function () use ($trajectId, $cats, $subs) {
        // ─── Hoofdcategorieën: normaliseren naar 100 totaal ────────────────
        $cats = weights_normalize_group($cats);
        foreach ($cats as $catId => $w) {
            weights_upsert_cat($trajectId, (int)$catId, (float)$w);
        }

        // ─── Subcategorieën per hoofd: groepeer & normaliseer ──────────────
        if ($subs) {
            $subIds = array_map('intval', array_keys($subs));
            $place  = implode(',', array_fill(0, count($subIds), '?'));
            $info   = db_all(
                "SELECT id, categorie_id FROM subcategorieen WHERE id IN ($place)",
                $subIds
            );
            $byGroup = []; // cat_id => [sub_id => weight]
            foreach ($info as $r) {
                $sid = (int)$r['id'];
                if (isset($subs[$sid])) {
                    $byGroup[(int)$r['categorie_id']][$sid] = (float)$subs[$sid];
                }
            }
            foreach ($byGroup as $group) {
                $group = weights_normalize_group($group);
                foreach ($group as $subId => $w) {
                    weights_upsert_sub($trajectId, (int)$subId, (float)$w);
                }
            }
        }

        audit_log('weights_updated', 'traject', $trajectId, '');
    });
}

/**
 * Normaliseer een associatieve array met waarden naar som 100.
 * Bij som=0: gelijke verdeling.
 */
function weights_normalize_group(array $values): array {
    if (!$values) return [];
    $values = array_map(fn($v) => max(0, (float)$v), $values);
    $sum    = array_sum($values);
    $out    = [];
    if ($sum <= 0) {
        $even = 100 / count($values);
        foreach ($values as $k => $_) $out[$k] = round($even, 3);
    } else {
        foreach ($values as $k => $v) $out[$k] = round(100 * $v / $sum, 3);
    }
    // Corrigeer afrondingsverschil op laatste sleutel zodat som exact 100 is
    $keys   = array_keys($out);
    $lastK  = end($keys);
    $diff   = 100 - array_sum($out);
    $out[$lastK] = round($out[$lastK] + $diff, 3);
    return $out;
}

function weights_upsert_cat(int $trajectId, int $catId, float $weight): void {
    $exists = db_value(
        'SELECT id FROM weights
          WHERE traject_id = :t AND categorie_id = :c AND subcategorie_id IS NULL',
        [':t' => $trajectId, ':c' => $catId]
    );
    if ($exists) {
        db_update('weights', ['weight' => $weight], 'id = :id', [':id' => (int)$exists]);
    } else {
        db_insert('weights', [
            'traject_id'      => $trajectId,
            'categorie_id'    => $catId,
            'subcategorie_id' => null,
            'weight'          => $weight,
        ]);
    }
}

function weights_upsert_sub(int $trajectId, int $subId, float $weight): void {
    $exists = db_value(
        'SELECT id FROM weights
          WHERE traject_id = :t AND subcategorie_id = :s AND categorie_id IS NULL',
        [':t' => $trajectId, ':s' => $subId]
    );
    if ($exists) {
        db_update('weights', ['weight' => $weight], 'id = :id', [':id' => (int)$exists]);
    } else {
        db_insert('weights', [
            'traject_id'      => $trajectId,
            'categorie_id'    => null,
            'subcategorie_id' => $subId,
            'weight'          => $weight,
        ]);
    }
}

/**
 * Haal de categorie/subcategorie-structuur op voor een traject.
 * Subcategorieën komen uit subcategorieen waar traject_id = :t OR NULL.
 */
function structure_load(int $trajectId): array {
    $cats = db_all(
        'SELECT id, code, name, type, sort_order
           FROM categorieen
          ORDER BY sort_order, id'
    );
    $subs = db_all(
        'SELECT s.id, s.categorie_id, s.traject_id, s.applicatiesoort_id, s.name, s.sort_order,
                a.code AS app_code, a.label AS app_label
           FROM subcategorieen s
           LEFT JOIN applicatiesoorten a ON a.id = s.applicatiesoort_id
          WHERE s.traject_id = :t
          ORDER BY s.categorie_id, s.sort_order, s.id',
        [':t' => $trajectId]
    );
    $byCat = [];
    foreach ($subs as $s) {
        $byCat[(int)$s['categorie_id']][] = $s;
    }
    foreach ($cats as &$c) {
        $c['subs'] = $byCat[(int)$c['id']] ?? [];
    }
    return $cats;
}
