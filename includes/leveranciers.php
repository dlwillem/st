<?php
/**
 * Leveranciers — CRUD per traject.
 * Statussen: actief, onder_review, afgewezen.
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

const LEVERANCIER_STATUSES = ['actief', 'onder_review', 'afgewezen'];

function leverancier_status_label(string $s): string {
    return [
        'actief'       => 'Actief',
        'onder_review' => 'Onder review',
        'afgewezen'    => 'Afgewezen',
    ][$s] ?? $s;
}

function leverancier_status_badge(string $s): string {
    $map = [
        'actief'       => ['green',  'Actief'],
        'onder_review' => ['amber',  '⚠ Onder review'],
        'afgewezen'    => ['red',    'Afgewezen'],
    ];
    [$color, $label] = $map[$s] ?? ['gray', $s];
    return '<span class="badge ' . $color . '">' . h($label) . '</span>';
}

function leverancier_create(int $trajectId, array $data): int {
    if (trim((string)($data['name'] ?? '')) === '') {
        throw new RuntimeException('Naam is verplicht.');
    }
    $status = (string)($data['status'] ?? 'actief');
    if (!in_array($status, LEVERANCIER_STATUSES, true)) {
        throw new RuntimeException('Ongeldige status.');
    }
    $email = trim((string)($data['contact_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Ongeldig e-mailadres.');
    }

    $id = db_insert('leveranciers', [
        'traject_id'    => $trajectId,
        'name'          => trim((string)$data['name']),
        'contact_name'  => !empty($data['contact_name'])  ? trim((string)$data['contact_name'])  : null,
        'contact_email' => $email !== '' ? $email : null,
        'website'       => !empty($data['website'])       ? trim((string)$data['website'])       : null,
        'notes'         => !empty($data['notes'])         ? trim((string)$data['notes'])         : null,
        'status'        => $status,
        'created_at'    => date('Y-m-d H:i:s'),
    ]);
    audit_log('leverancier_created', 'leverancier', $id, (string)$data['name']);
    return $id;
}

function leverancier_update(int $id, int $trajectId, array $data): void {
    $existing = db_one(
        'SELECT * FROM leveranciers WHERE id = :id AND traject_id = :t',
        [':id' => $id, ':t' => $trajectId]
    );
    if (!$existing) throw new RuntimeException('Leverancier niet gevonden.');

    if (isset($data['status']) && !in_array($data['status'], LEVERANCIER_STATUSES, true)) {
        throw new RuntimeException('Ongeldige status.');
    }
    if (!empty($data['contact_email'])
        && !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Ongeldig e-mailadres.');
    }

    $allowed = ['name','contact_name','contact_email','website','notes','status'];
    $filtered = array_intersect_key($data, array_flip($allowed));
    foreach (['contact_name','contact_email','website','notes'] as $k) {
        if (array_key_exists($k, $filtered) && trim((string)$filtered[$k]) === '') {
            $filtered[$k] = null;
        }
    }
    if (isset($filtered['name']) && trim((string)$filtered['name']) === '') {
        throw new RuntimeException('Naam is verplicht.');
    }
    if (!$filtered) return;

    db_update('leveranciers', $filtered, 'id = :id', [':id' => $id]);
    audit_log('leverancier_updated', 'leverancier', $id, (string)($data['name'] ?? $existing['name']));
}

function leverancier_set_status(int $id, int $trajectId, string $status): void {
    if (!in_array($status, LEVERANCIER_STATUSES, true)) return;
    db_update('leveranciers', ['status' => $status],
        'id = :id AND traject_id = :t',
        [':id' => $id, ':t' => $trajectId]);
    audit_log('leverancier_status_changed', 'leverancier', $id, $status);
}

function leverancier_delete(int $id, int $trajectId): void {
    $row = db_one(
        'SELECT name FROM leveranciers WHERE id = :id AND traject_id = :t',
        [':id' => $id, ':t' => $trajectId]
    );
    if (!$row) return;
    db_exec('DELETE FROM leveranciers WHERE id = :id', [':id' => $id]);
    audit_log('leverancier_deleted', 'leverancier', $id, (string)$row['name']);
}

function leveranciers_list(int $trajectId, array $filters = []): array {
    $sql = 'SELECT * FROM leveranciers WHERE traject_id = :t';
    $params = [':t' => $trajectId];
    if (!empty($filters['status']) && in_array($filters['status'], LEVERANCIER_STATUSES, true)) {
        $sql .= ' AND status = :s';
        $params[':s'] = $filters['status'];
    }
    if (!empty($filters['q'])) {
        $sql .= ' AND (name LIKE :q OR contact_name LIKE :q OR contact_email LIKE :q OR notes LIKE :q)';
        $params[':q'] = '%' . $filters['q'] . '%';
    }
    $sql .= ' ORDER BY
        CASE status
          WHEN "actief"       THEN 0
          WHEN "onder_review" THEN 1
          ELSE 2
        END, name ASC';
    return db_all($sql, $params);
}
