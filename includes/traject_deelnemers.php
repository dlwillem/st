<?php
/**
 * DKG collega's (traject-deelnemers).
 *
 * Traject-brede lijst van beoordelaars (key-users, BA's, architecten, mgt).
 * Per collega wordt aangevinkt welke hoofdcategorieën (FUNC/NFR/VEND/LIC/SUP)
 * zij standaard scoren. DEMO wordt per ronde bepaald (niet via de matrix).
 *
 * Bij het openen van een ronde klikt een key-user "Uitnodigingen versturen".
 * Dit expandeert de matrix naar scoring_deelnemers-rijen voor die ronde.
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

const TD_SCOPES = ['FUNC', 'NFR', 'VEND', 'LIC', 'SUP'];

/**
 * Lijst collega's voor een traject, inclusief scope-vinkjes.
 *
 * @return array<int,array{id:int,name:string,email:string,created_at:string,scopes:string[]}>
 */
function traject_deelnemers_list(int $trajectId): array {
    $rows = db_all(
        'SELECT id, name, email, created_at
           FROM traject_deelnemers
          WHERE traject_id = :t
          ORDER BY name',
        [':t' => $trajectId]
    );
    if (!$rows) return [];
    $ids = array_column($rows, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $scopeRows = db_all(
        "SELECT traject_deelnemer_id, scope
           FROM traject_deelnemer_scopes
          WHERE traject_deelnemer_id IN ($ph)",
        $ids
    );
    $scopesByTd = [];
    foreach ($scopeRows as $s) {
        $scopesByTd[(int)$s['traject_deelnemer_id']][] = (string)$s['scope'];
    }
    foreach ($rows as &$r) {
        $r['id']     = (int)$r['id'];
        $r['scopes'] = $scopesByTd[(int)$r['id']] ?? [];
    }
    return $rows;
}

function traject_deelnemer_create(int $trajectId, string $name, string $email, array $scopes = []): int {
    $name  = trim($name);
    $email = trim($email);
    if ($name === '' || $email === '') {
        throw new RuntimeException('Naam en e-mail zijn verplicht.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Ongeldig e-mailadres.');
    }
    $dupe = db_value(
        'SELECT id FROM traject_deelnemers WHERE traject_id = :t AND email = :e',
        [':t' => $trajectId, ':e' => $email]
    );
    if ($dupe) {
        throw new RuntimeException('Deze collega staat al in de lijst.');
    }
    $userId = db_value('SELECT id FROM users WHERE LOWER(email) = LOWER(:e)', [':e' => $email]);
    $id = db_insert('traject_deelnemers', [
        'traject_id' => $trajectId,
        'user_id'    => $userId ? (int)$userId : null,
        'name'       => $name,
        'email'      => $email,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    if ($scopes) traject_deelnemer_scopes_set($id, $scopes);
    audit_log('traject_deelnemer_added', 'traject_deelnemer', $id, $email);
    return $id;
}

function traject_deelnemer_update(int $id, int $trajectId, string $name, string $email): void {
    $name  = trim($name);
    $email = trim($email);
    if ($name === '' || $email === '') {
        throw new RuntimeException('Naam en e-mail zijn verplicht.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Ongeldig e-mailadres.');
    }
    $owner = db_value('SELECT traject_id FROM traject_deelnemers WHERE id = :id', [':id' => $id]);
    if ((int)$owner !== $trajectId) throw new RuntimeException('Collega niet gevonden.');
    db_update('traject_deelnemers', ['name' => $name, 'email' => $email],
        'id = :id', [':id' => $id]);
}

function traject_deelnemer_delete(int $id, int $trajectId): void {
    $owner = db_value('SELECT traject_id FROM traject_deelnemers WHERE id = :id', [':id' => $id]);
    if ((int)$owner !== $trajectId) return;
    db_exec('DELETE FROM traject_deelnemers WHERE id = :id', [':id' => $id]);
    audit_log('traject_deelnemer_deleted', 'traject_deelnemer', $id, '');
}

function traject_deelnemer_scopes_set(int $tdId, array $scopes): void {
    $valid = array_values(array_intersect(TD_SCOPES, $scopes));
    db_exec('DELETE FROM traject_deelnemer_scopes WHERE traject_deelnemer_id = :id',
        [':id' => $tdId]);
    foreach ($valid as $s) {
        db_insert('traject_deelnemer_scopes', [
            'traject_deelnemer_id' => $tdId,
            'scope'                => $s,
        ]);
    }
}

/**
 * Collega's die voor een bepaalde scope zijn aangevinkt (voor FUNC/NFR/VEND/LIC/SUP).
 * Voor DEMO: geef alle collega's terug (selectie gebeurt per ronde).
 *
 * @return array<int,array{id:int,name:string,email:string}>
 */
function traject_deelnemers_for_scope(int $trajectId, string $scope): array {
    if ($scope === 'DEMO') {
        return db_all(
            'SELECT id, name, email FROM traject_deelnemers
              WHERE traject_id = :t
              ORDER BY name',
            [':t' => $trajectId]
        );
    }
    return db_all(
        'SELECT td.id, td.name, td.email
           FROM traject_deelnemers td
           JOIN traject_deelnemer_scopes s ON s.traject_deelnemer_id = td.id
          WHERE td.traject_id = :t AND s.scope = :sc
          ORDER BY td.name',
        [':t' => $trajectId, ':sc' => $scope]
    );
}

/**
 * Stuur uitnodigingen voor een ronde.
 *
 * - Non-DEMO: nodigt alle collega's uit met die scope in hun matrix die nog
 *   geen scoring_deelnemer-rij hebben in deze ronde.
 * - DEMO: nodigt alleen de expliciet gekozen $tdIds uit.
 *
 * @return array{invited:int,skipped:int}
 */
function traject_deelnemers_invite_round(int $rondeId, int $trajectId, ?array $tdIds = null): array {
    $ronde = db_one('SELECT id, scope, traject_id, status FROM scoring_rondes WHERE id = :r',
        [':r' => $rondeId]);
    if (!$ronde || (int)$ronde['traject_id'] !== $trajectId) {
        throw new RuntimeException('Ronde niet gevonden.');
    }
    $scope = (string)$ronde['scope'];

    if ($tdIds !== null) {
        $tdIds = array_values(array_unique(array_map('intval', $tdIds)));
        if (!$tdIds) return ['invited' => 0, 'skipped' => 0];
        $ph = implode(',', array_fill(0, count($tdIds), '?'));
        $cands = db_all(
            "SELECT id, name, email FROM traject_deelnemers
              WHERE traject_id = ? AND id IN ($ph)
              ORDER BY name",
            array_merge([$trajectId], $tdIds)
        );
    } else {
        $cands = traject_deelnemers_for_scope($trajectId, $scope);
    }

    $existing = db_all(
        'SELECT traject_deelnemer_id, email FROM scoring_deelnemers WHERE ronde_id = :r',
        [':r' => $rondeId]
    );
    $havenTd    = [];
    $havenEmail = [];
    foreach ($existing as $e) {
        if ($e['traject_deelnemer_id'] !== null) $havenTd[(int)$e['traject_deelnemer_id']] = true;
        $havenEmail[mb_strtolower((string)$e['email'])] = true;
    }

    $invited = 0; $skipped = 0;
    foreach ($cands as $c) {
        $tdId = (int)$c['id'];
        if (isset($havenTd[$tdId]) || isset($havenEmail[mb_strtolower((string)$c['email'])])) {
            $skipped++; continue;
        }
        traject_deelnemer_invite_to_round($rondeId, $tdId, (string)$c['name'], (string)$c['email']);
        $invited++;
    }
    return ['invited' => $invited, 'skipped' => $skipped];
}

function traject_deelnemer_invite_to_round(int $rondeId, int $tdId, string $name, string $email): int {
    $ronde = db_one('SELECT leverancier_id FROM scoring_rondes WHERE id = :r', [':r' => $rondeId]);
    if (!$ronde) throw new RuntimeException('Ronde niet gevonden.');

    $token = deelnemer_generate_token();
    $id = db_insert('scoring_deelnemers', [
        'ronde_id'             => $rondeId,
        'leverancier_id'       => (int)$ronde['leverancier_id'],
        'traject_deelnemer_id' => $tdId,
        'name'                 => $name,
        'email'                => $email,
        'token'                => $token,
        'token_expires'        => date('Y-m-d H:i:s', time() + SCORE_TOKEN_TTL_DAYS * 86400),
        'invited_at'           => date('Y-m-d H:i:s'),
    ]);
    deelnemer_send_invite_mail($id);
    audit_log('deelnemer_invited', 'scoring_deelnemer', $id, $name . ' <' . $email . '>');
    return $id;
}
