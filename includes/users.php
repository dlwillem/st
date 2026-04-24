<?php
/**
 * Users — CRUD + role/activation helpers.
 * Rollen komen uit de users.role ENUM:
 *   architect         — volledige rechten incl. gebruikersbeheer (super-user)
 *   business_owner    — requirements/leveranciers/weging/rapportage binnen eigen trajecten
 *   business_analist  — requirements + rapportage + scores invullen binnen eigen trajecten
 *   key_user          — read-only inzage + scores invullen binnen eigen trajecten
 * Permissies worden beheerd via includes/authz.php (can()).
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

const USER_ROLES = ['architect', 'business_owner', 'business_analist', 'key_user'];

function user_role_label(string $role): string {
    return [
        'architect'         => 'Architect',
        'business_owner'    => 'Business owner',
        'business_analist'  => 'Business analist',
        'key_user'          => 'Key-user',
    ][$role] ?? $role;
}

function user_role_badge(string $role): string {
    $cls = [
        'architect'         => 'indigo',
        'business_owner'    => 'blue',
        'business_analist'  => 'amber',
        'key_user'          => 'gray',
    ][$role] ?? 'gray';
    return '<span class="badge ' . h($cls) . '">' . h(user_role_label($role)) . '</span>';
}

function users_list(string $q = '', string $role = '', ?int $active = null): array {
    $sql    = 'SELECT id, name, email, role, active, last_login, created_at FROM users WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (name LIKE :q OR email LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
    if ($role !== '' && in_array($role, USER_ROLES, true)) {
        $sql .= ' AND role = :r';
        $params[':r'] = $role;
    }
    if ($active !== null) {
        $sql .= ' AND active = :a';
        $params[':a'] = $active;
    }
    $sql .= ' ORDER BY active DESC, name';
    return db_all($sql, $params);
}

function user_find(int $id): ?array {
    return db_one('SELECT * FROM users WHERE id = :id', [':id' => $id]);
}

function user_find_by_email(string $email): ?array {
    return db_one('SELECT * FROM users WHERE email = :e', [':e' => mb_strtolower(trim($email))]);
}

function user_create(string $name, string $email, string $role, string $password): int {
    $name  = trim($name);
    $email = mb_strtolower(trim($email));
    if ($name === '')                                throw new RuntimeException('Naam is verplicht.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))  throw new RuntimeException('Ongeldig e-mailadres.');
    if (!in_array($role, USER_ROLES, true))          throw new RuntimeException('Ongeldige rol.');
    if (strlen($password) < 8)                       throw new RuntimeException('Wachtwoord minimaal 8 tekens.');
    if (user_find_by_email($email))                  throw new RuntimeException('E-mailadres bestaat al.');

    $id = db_insert('users', [
        'name'          => $name,
        'email'         => $email,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'role'          => $role,
        'active'        => 1,
        'created_at'    => date('Y-m-d H:i:s'),
    ]);
    audit_log('user_create', 'user', $id, $email . ' (' . $role . ')');
    return $id;
}

function user_update(int $id, string $name, string $email, string $role, bool $active): void {
    $existing = user_find($id);
    if (!$existing) throw new RuntimeException('Gebruiker niet gevonden.');
    $name  = trim($name);
    $email = mb_strtolower(trim($email));
    if ($name === '')                                throw new RuntimeException('Naam is verplicht.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))  throw new RuntimeException('Ongeldig e-mailadres.');
    if (!in_array($role, USER_ROLES, true))          throw new RuntimeException('Ongeldige rol.');

    // e-mail uniek (behalve voor huidige user)
    $dup = db_value('SELECT id FROM users WHERE email = :e AND id <> :id', [':e' => $email, ':id' => $id]);
    if ($dup) throw new RuntimeException('E-mailadres is al in gebruik.');

    // Laatste actieve architect niet downgraden/deactiveren
    if ($existing['role'] === 'architect' && ($role !== 'architect' || !$active)) {
        $otherArchitects = (int)db_value(
            'SELECT COUNT(*) FROM users WHERE role = "architect" AND active = 1 AND id <> :id',
            [':id' => $id]
        );
        if ($otherArchitects === 0) {
            throw new RuntimeException('Er moet minimaal één actieve architect overblijven.');
        }
    }

    db_update('users', [
        'name'   => $name,
        'email'  => $email,
        'role'   => $role,
        'active' => $active ? 1 : 0,
    ], 'id = :id', [':id' => $id]);

    audit_log('user_update', 'user', $id, $email . ' (' . $role . ', ' . ($active ? 'actief' : 'inactief') . ')');
}

function user_set_password(int $id, string $password): void {
    if (strlen($password) < 8) throw new RuntimeException('Wachtwoord minimaal 8 tekens.');
    db_update('users',
        ['password_hash' => password_hash($password, PASSWORD_BCRYPT)],
        'id = :id', [':id' => $id]);
    audit_log('user_password_reset', 'user', $id, '');
}

function user_delete(int $id): void {
    $u = user_find($id);
    if (!$u) throw new RuntimeException('Gebruiker niet gevonden.');
    if ($u['role'] === 'architect') {
        $other = (int)db_value(
            'SELECT COUNT(*) FROM users WHERE role = "architect" AND active = 1 AND id <> :id',
            [':id' => $id]
        );
        if ($other === 0) throw new RuntimeException('Kan laatste architect niet verwijderen.');
    }
    db_exec('DELETE FROM users WHERE id = :id', [':id' => $id]);
    audit_log('user_delete', 'user', $id, $u['email']);
}
