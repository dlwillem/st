<?php
/**
 * Scoring-rondes — nieuw model (per leverancier × scope).
 *
 * Ronde-scope is een hoofdcategorie (FUNC/NFR/VEND/LIC/SUP) of 'DEMO'.
 * Per (traject, leverancier, scope) bestaat er maximaal één ronde.
 * Statussen: concept → open → gesloten (eenmaal gesloten niet meer herop.).
 *
 * Beoordelaars zijn `scoring_deelnemers` met token-based invite-links.
 * Intern of extern maakt voor de data niet uit; intern team krijgt gewoon
 * ook een token-link (geen login vereist op /pages/score.php).
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

const RONDE_STATUSES = ['concept', 'open', 'gesloten'];
const RONDE_SCOPES   = ['FUNC', 'NFR', 'VEND', 'LIC', 'SUP', 'DEMO'];

function ronde_status_label(string $s): string {
    return [
        'concept'  => 'Concept',
        'open'     => 'Open',
        'gesloten' => 'Gesloten',
    ][$s] ?? $s;
}

function ronde_status_badge(string $s): string {
    $map = [
        'concept'  => ['gray',   'Concept'],
        'open'     => ['green',  'Open'],
        'gesloten' => ['indigo', 'Gesloten'],
    ];
    [$color, $label] = $map[$s] ?? ['gray', $s];
    return '<span class="badge ' . $color . '">' . h($label) . '</span>';
}

function ronde_scope_style(string $scope): array {
    if ($scope === 'DEMO') return ['icon' => 'monitor', 'color' => 'blue'];
    return requirement_cat_style($scope);
}

// ─── CRUD ────────────────────────────────────────────────────────────────────

/**
 * Maakt een ronde voor (traject, leverancier, scope). Geeft ronde-id terug.
 * Bestond er al één dan wordt die id teruggegeven (idempotent).
 */
function ronde_upsert(int $trajectId, int $leverancierId, string $scope, array $data = []): int {
    if (!in_array($scope, RONDE_SCOPES, true)) {
        throw new RuntimeException('Onbekende scope: ' . $scope);
    }
    $lev = db_one('SELECT id FROM leveranciers WHERE id = :l AND traject_id = :t',
        [':l' => $leverancierId, ':t' => $trajectId]);
    if (!$lev) throw new RuntimeException('Leverancier hoort niet bij dit traject.');

    $existing = db_value(
        'SELECT id FROM scoring_rondes WHERE traject_id = :t AND leverancier_id = :l AND scope = :s',
        [':t' => $trajectId, ':l' => $leverancierId, ':s' => $scope]
    );
    if ($existing) return (int)$existing;

    $user = current_user();
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        $name = $scope . '-ronde';
    }
    $id = db_insert('scoring_rondes', [
        'traject_id'     => $trajectId,
        'leverancier_id' => $leverancierId,
        'scope'          => $scope,
        'name'           => $name,
        'description'    => !empty($data['description']) ? trim((string)$data['description']) : null,
        'start_date'     => !empty($data['start_date']) ? (string)$data['start_date'] : null,
        'end_date'       => !empty($data['end_date'])   ? (string)$data['end_date']   : null,
        'status'         => 'concept',
        'created_by'     => $user['id'] ?? null,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
    audit_log('ronde_created', 'scoring_ronde', $id, $scope . ' / lev=' . $leverancierId);
    return $id;
}

function ronde_update(int $id, int $trajectId, array $data): void {
    $existing = db_one(
        'SELECT * FROM scoring_rondes WHERE id = :id AND traject_id = :t',
        [':id' => $id, ':t' => $trajectId]
    );
    if (!$existing) throw new RuntimeException('Ronde niet gevonden.');
    if ($existing['status'] === 'gesloten') {
        throw new RuntimeException('Een gesloten ronde kan niet meer bewerkt worden.');
    }

    $allowed  = ['name','description','start_date','end_date'];
    $filtered = array_intersect_key($data, array_flip($allowed));
    foreach (['description','start_date','end_date'] as $k) {
        if (array_key_exists($k, $filtered) && trim((string)$filtered[$k]) === '') {
            $filtered[$k] = null;
        }
    }
    if (isset($filtered['name']) && trim((string)$filtered['name']) === '') {
        throw new RuntimeException('Naam is verplicht.');
    }
    if (!$filtered) return;
    db_update('scoring_rondes', $filtered, 'id = :id', [':id' => $id]);
    audit_log('ronde_updated', 'scoring_ronde', $id, (string)($data['name'] ?? $existing['name']));
}

function ronde_set_status(int $id, int $trajectId, string $status): void {
    if (!in_array($status, RONDE_STATUSES, true)) return;
    $existing = db_one(
        'SELECT * FROM scoring_rondes WHERE id = :id AND traject_id = :t',
        [':id' => $id, ':t' => $trajectId]
    );
    if (!$existing) throw new RuntimeException('Ronde niet gevonden.');
    if ($existing['status'] === 'gesloten' && $status !== 'gesloten') {
        throw new RuntimeException('Een gesloten ronde kan niet heropend worden.');
    }
    $valid = [
        'concept'  => ['open'],
        'open'     => ['gesloten'],
        'gesloten' => [],
    ];
    if (!in_array($status, $valid[$existing['status']] ?? [], true)) {
        throw new RuntimeException('Ongeldige statusovergang.');
    }

    $update = ['status' => $status];
    if ($status === 'gesloten') {
        $user = current_user();
        $update['closed_at'] = date('Y-m-d H:i:s');
        $update['closed_by'] = $user['id'] ?? null;
    }
    db_update('scoring_rondes', $update, 'id = :id', [':id' => $id]);
    audit_log('ronde_status_' . $status, 'scoring_ronde', $id, (string)$existing['name']);
}

function ronde_delete(int $id, int $trajectId): void {
    $row = db_one(
        'SELECT name, status FROM scoring_rondes WHERE id = :id AND traject_id = :t',
        [':id' => $id, ':t' => $trajectId]
    );
    if (!$row) return;
    if ($row['status'] === 'gesloten') {
        throw new RuntimeException('Een gesloten ronde kan niet verwijderd worden (auditrecord).');
    }
    db_exec('DELETE FROM scoring_rondes WHERE id = :id', [':id' => $id]);
    audit_log('ronde_deleted', 'scoring_ronde', $id, (string)$row['name']);
}

function ronde_get(int $id): ?array {
    return db_one(
        'SELECT r.*, t.name AS traject_name,
                l.name AS leverancier_name,
                cu.name AS created_by_name, xu.name AS closed_by_name
           FROM scoring_rondes r
           JOIN trajecten    t  ON t.id = r.traject_id
           JOIN leveranciers l  ON l.id = r.leverancier_id
           LEFT JOIN users   cu ON cu.id = r.created_by
           LEFT JOIN users   xu ON xu.id = r.closed_by
          WHERE r.id = :id',
        [':id' => $id]
    );
}

/**
 * Matrix per traject: alle rondes, geïndexeerd op [leverancier_id][scope].
 * Elke cel bevat ronde-info + deelnemer-stats.
 */
function scoring_matrix(int $trajectId): array {
    $rows = db_all(
        'SELECT r.id, r.leverancier_id, r.scope, r.status, r.name,
                r.start_date, r.end_date, r.closed_at,
                (SELECT COUNT(*) FROM scoring_deelnemers d WHERE d.ronde_id = r.id) AS deelnemers,
                (SELECT COUNT(*) FROM scoring_deelnemers d
                  WHERE d.ronde_id = r.id AND d.completed_at IS NOT NULL) AS completed
           FROM scoring_rondes r
          WHERE r.traject_id = :t
          ORDER BY r.leverancier_id, FIELD(r.scope, "FUNC","NFR","VEND","LIC","SUP","DEMO")',
        [':t' => $trajectId]
    );
    $matrix = [];
    foreach ($rows as $r) {
        $matrix[(int)$r['leverancier_id']][(string)$r['scope']] = $r;
    }
    return $matrix;
}

// ─── Deelnemers (token-invites) ──────────────────────────────────────────────

/**
 * Genereert een nieuwe token. Retourneert ['plain' => ..., 'hash' => ...].
 * Plaintext gaat naar de mail-link; hash wordt in DB opgeslagen.
 */
function deelnemer_generate_token(): array {
    $plain = bin2hex(random_bytes(24));
    return ['plain' => $plain, 'hash' => deelnemer_token_hash($plain)];
}

function deelnemer_token_hash(string $plain): string {
    return hash('sha256', $plain);
}

function deelnemer_invite_url(string $token): string {
    return APP_BASE_URL . '/pages/score.php?token=' . urlencode($token);
}

/**
 * Voegt een beoordelaar toe aan een ronde en verstuurt de uitnodiging.
 * De beoordelaar hoort impliciet bij de leverancier van de ronde.
 */
function deelnemer_create(int $rondeId, string $name, string $email, ?int $userId = null): int {
    $name  = trim($name);
    $email = trim($email);
    if ($name === '' || $email === '') {
        throw new RuntimeException('Naam en e-mail zijn verplicht.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Ongeldig e-mailadres.');
    }
    $ronde = db_one('SELECT leverancier_id, status FROM scoring_rondes WHERE id = :r', [':r' => $rondeId]);
    if (!$ronde) throw new RuntimeException('Ronde niet gevonden.');

    $dupe = db_value(
        'SELECT id FROM scoring_deelnemers WHERE ronde_id = :r AND email = :e',
        [':r' => $rondeId, ':e' => $email]
    );
    if ($dupe) return (int)$dupe;

    $tok = deelnemer_generate_token();
    $id = db_insert('scoring_deelnemers', [
        'ronde_id'       => $rondeId,
        'leverancier_id' => (int)$ronde['leverancier_id'],
        'name'           => $name,
        'email'          => $email,
        'token'          => $tok['hash'],
        'token_expires'  => date('Y-m-d H:i:s', time() + SCORE_TOKEN_TTL_DAYS * 86400),
        'invited_at'     => date('Y-m-d H:i:s'),
    ]);
    deelnemer_send_invite_mail($id, $tok['plain']);
    audit_log('deelnemer_invited', 'scoring_deelnemer', $id, $name . ' <' . $email . '>');
    return $id;
}

function deelnemer_resend(int $id): void {
    $d = db_one('SELECT * FROM scoring_deelnemers WHERE id = :id', [':id' => $id]);
    if (!$d) throw new RuntimeException('Deelnemer niet gevonden.');
    if ($d['completed_at']) {
        throw new RuntimeException('Deelnemer heeft de ronde al afgerond.');
    }
    $tok = deelnemer_generate_token();
    db_update('scoring_deelnemers', [
        'token'         => $tok['hash'],
        'token_expires' => date('Y-m-d H:i:s', time() + SCORE_TOKEN_TTL_DAYS * 86400),
        'invited_at'    => date('Y-m-d H:i:s'),
    ], 'id = :id', [':id' => $id]);
    deelnemer_send_invite_mail($id, $tok['plain']);
    audit_log('deelnemer_resent', 'scoring_deelnemer', $id, (string)$d['email']);
}

function deelnemer_delete(int $id): void {
    $d = db_one('SELECT email FROM scoring_deelnemers WHERE id = :id', [':id' => $id]);
    if (!$d) return;
    db_exec('DELETE FROM scoring_deelnemers WHERE id = :id', [':id' => $id]);
    audit_log('deelnemer_deleted', 'scoring_deelnemer', $id, (string)$d['email']);
}

function deelnemers_list_for_ronde(int $rondeId): array {
    return db_all(
        'SELECT d.*,
                (SELECT COUNT(*) FROM scores s WHERE s.deelnemer_id = d.id) AS scores
           FROM scoring_deelnemers d
          WHERE d.ronde_id = :r
          ORDER BY d.name',
        [':r' => $rondeId]
    );
}

function deelnemer_find_by_token(string $token): ?array {
    return db_one(
        'SELECT d.*, r.status AS ronde_status, r.name AS ronde_name,
                r.traject_id, r.leverancier_id AS ronde_leverancier_id, r.scope,
                l.name AS leverancier_name, t.name AS traject_name
           FROM scoring_deelnemers d
           JOIN scoring_rondes r ON r.id = d.ronde_id
           JOIN leveranciers   l ON l.id = r.leverancier_id
           JOIN trajecten      t ON t.id = r.traject_id
          WHERE d.token = :tk',
        [':tk' => deelnemer_token_hash($token)]
    );
}

/**
 * Zoekt deelnemer op id + load ronde/traject/leverancier-context.
 * Voor gebruik vanuit admin-mode (session-authenticated, geen token nodig).
 */
function deelnemer_find_by_id(int $id): ?array {
    return db_one(
        'SELECT d.*, r.status AS ronde_status, r.name AS ronde_name,
                r.traject_id, r.leverancier_id AS ronde_leverancier_id, r.scope,
                l.name AS leverancier_name, t.name AS traject_name
           FROM scoring_deelnemers d
           JOIN scoring_rondes r ON r.id = d.ronde_id
           JOIN leveranciers   l ON l.id = r.leverancier_id
           JOIN trajecten      t ON t.id = r.traject_id
          WHERE d.id = :id',
        [':id' => $id]
    );
}

function deelnemer_mark_completed(int $id): void {
    db_update('scoring_deelnemers', ['completed_at' => date('Y-m-d H:i:s')],
        'id = :id', [':id' => $id]);
    audit_log('deelnemer_completed', 'scoring_deelnemer', $id, '');
}

function deelnemer_send_invite_mail(int $id, string $plainToken): void {
    $d = db_one(
        'SELECT d.*, r.name AS ronde_name, r.scope, r.end_date,
                t.name AS traject_name,
                l.name AS leverancier_name
           FROM scoring_deelnemers d
           JOIN scoring_rondes r ON r.id = d.ronde_id
           JOIN trajecten      t ON t.id = r.traject_id
           JOIN leveranciers   l ON l.id = r.leverancier_id
          WHERE d.id = :id',
        [':id' => $id]
    );
    if (!$d) return;
    $url  = deelnemer_invite_url($plainToken);
    $exp  = date('d-m-Y', strtotime((string)$d['token_expires']));
    $subj = 'Uitnodiging scoring: ' . $d['scope'] . ' — ' . $d['leverancier_name']
          . ' (' . $d['traject_name'] . ')';
    $html = '<p>Beste ' . h($d['name']) . ',</p>'
          . '<p>Je bent uitgenodigd om <strong>' . h($d['leverancier_name']) . '</strong> '
          . 'te scoren op onderdeel <strong>' . h($d['scope']) . '</strong> '
          . 'in het traject <strong>' . h($d['traject_name']) . '</strong>.</p>'
          . '<p>Gebruik de onderstaande link — deze is persoonlijk en geldig t/m ' . $exp . ':</p>'
          . '<p><a href="' . h($url) . '">' . h($url) . '</a></p>'
          . '<p>Met vriendelijke groet,<br>' . h(setting_app_name()) . '</p>';
    send_mail((string)$d['email'], (string)$d['name'], $subj, $html);
}

// ─── Scores (save + load) ────────────────────────────────────────────────────

function score_upsert(int $rondeId, int $leverancierId, int $requirementId, ?int $deelnemerId, int $score, ?string $notes): void {
    $score = max(0, min(5, $score));
    $params = [
        ':r' => $rondeId, ':l' => $leverancierId, ':q' => $requirementId,
        ':d' => $deelnemerId,
    ];
    $existing = $deelnemerId
        ? db_value(
            'SELECT id FROM scores
              WHERE ronde_id = :r AND leverancier_id = :l
                AND requirement_id = :q AND deelnemer_id = :d',
            $params)
        : db_value(
            'SELECT id FROM scores
              WHERE ronde_id = :r AND leverancier_id = :l
                AND requirement_id = :q AND deelnemer_id IS NULL',
            [':r' => $rondeId, ':l' => $leverancierId, ':q' => $requirementId]);

    if ($score === 0) {
        if ($existing) db_exec('DELETE FROM scores WHERE id = :id', [':id' => (int)$existing]);
        return;
    }
    if ($existing) {
        db_update('scores', [
            'score'      => $score,
            'notes'      => ($notes !== null && $notes !== '') ? $notes : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', [':id' => (int)$existing]);
    } else {
        db_insert('scores', [
            'ronde_id'       => $rondeId,
            'leverancier_id' => $leverancierId,
            'requirement_id' => $requirementId,
            'deelnemer_id'   => $deelnemerId,
            'score'          => $score,
            'notes'          => ($notes !== null && $notes !== '') ? $notes : null,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
    }
}

function scores_for_deelnemer(int $deelnemerId): array {
    $rows = db_all(
        'SELECT requirement_id, score, notes FROM scores WHERE deelnemer_id = :d',
        [':d' => $deelnemerId]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['requirement_id']] = [
            'score' => (int)$r['score'],
            'notes' => (string)($r['notes'] ?? ''),
        ];
    }
    return $out;
}
