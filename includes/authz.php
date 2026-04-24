<?php
/**
 * Centrale autorisatie — capability-map per rol.
 *
 * Elke capability is een string (bv. 'requirements.edit').
 * Gebruik altijd can() in de app, nooit rechtstreeks has_role() of role-strings.
 * De autorisatiematrix in de FAQ wordt auto-gegenereerd uit deze map.
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

/**
 * Capabilities met een user-vriendelijk label. Het label wordt gebruikt
 * in de FAQ-matrix en moet kort Nederlands zijn.
 */
const CAPABILITIES = [
    'requirements.edit'   => 'Requirements aanmaken/wijzigen/verwijderen',
    'leveranciers.edit'   => 'Leveranciers beheren (incl. uitnodigen & Excel-upload)',
    'trajecten.edit'      => 'Trajecten aanmaken/wijzigen/archiveren',
    'repository.edit'     => 'Structuur stamdata beheren',
    'users.edit'          => 'Gebruikers en rollen beheren',
    'rapportage.view'     => 'Rapportage-scherm openen',
    'weging.edit'         => 'Weging per traject aanpassen',
    'scoring.manage'      => 'Scoring starten en beheren',
    'scoring.enter'       => 'Scores invullen in een ronde',
    'traject.collegas'    => 'Collega\'s koppelen aan een traject',
    'traject.view'        => 'Trajecten + alle tabs inzien (transparantie)',
    'settings.view'       => 'Instellingen / audit / structuur-stamdata-menu zien',
];

/**
 * Permissie-matrix: rol → [capabilities].
 * Houd deze in sync met de matrix die je met stakeholders hebt afgestemd.
 */
const ROLE_CAPABILITIES = [
    'architect' => [
        'requirements.edit', 'leveranciers.edit', 'trajecten.edit',
        'repository.edit', 'users.edit',
        'rapportage.view', 'weging.edit', 'scoring.manage', 'scoring.enter',
        'traject.collegas', 'traject.view', 'settings.view',
    ],
    'business_owner' => [
        'requirements.edit', 'leveranciers.edit',
        'rapportage.view', 'weging.edit', 'scoring.enter',
        'traject.view',
    ],
    'business_analist' => [
        'requirements.edit',
        'rapportage.view', 'scoring.enter',
        'traject.view',
    ],
    'key_user' => [
        // Read-only toegang tot toegewezen trajecten + scores invullen via link.
        'traject.view',
        'scoring.enter',
    ],
];

/**
 * Check of de (huidige) gebruiker een capability heeft.
 * Onbekende rollen of niet-ingelogde users → false.
 */
function can(string $capability, ?array $user = null): bool {
    $user = $user ?? current_user();
    if (!$user || empty($user['role'])) return false;
    $role = (string)$user['role'];
    $caps = ROLE_CAPABILITIES[$role] ?? [];
    return in_array($capability, $caps, true);
}

/**
 * 403 als de capability ontbreekt.
 */
function require_can(string $capability): void {
    require_login();
    if (!can($capability)) {
        http_response_code(403);
        exit('Onvoldoende rechten.');
    }
}

/**
 * Lijst capabilities die een rol heeft (voor UI/FAQ).
 */
function role_capabilities(string $role): array {
    return ROLE_CAPABILITIES[$role] ?? [];
}

/**
 * Rol-scoping: architect en management zien alle trajecten.
 * BA en key_user zien alleen trajecten waaraan ze zijn gekoppeld via
 * traject_deelnemers (match op e-mail).
 *
 * Retourneert een lijst traject-ID's, of null wanneer de gebruiker ongescoped
 * is (alles zien). Lege array = geen toegang tot enige traject.
 */
function user_allowed_traject_ids(?array $user = null): ?array {
    $user = $user ?? current_user();
    if (!$user) return [];
    $role = (string)($user['role'] ?? '');
    if ($role === 'architect') {
        return null; // ongescoped — zie alles
    }
    $uid   = (int)($user['id'] ?? 0);
    $email = mb_strtolower((string)($user['email'] ?? ''));
    if (!$uid && $email === '') return [];
    // Gebruik user_id alleen als de kolom bestaat (migratie `migrate_td_userid.php`
    // nog niet gedraaid → val terug op e-mail-matching).
    static $hasUserIdCol = null;
    if ($hasUserIdCol === null) {
        $hasUserIdCol = (bool)db_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'traject_deelnemers'
                AND COLUMN_NAME = 'user_id'"
        );
    }
    if ($hasUserIdCol) {
        $rows = db_all(
            'SELECT DISTINCT traject_id FROM traject_deelnemers
              WHERE (user_id = :u) OR (user_id IS NULL AND LOWER(email) = :e)',
            [':u' => $uid, ':e' => $email]
        );
    } else {
        $rows = db_all(
            'SELECT DISTINCT traject_id FROM traject_deelnemers WHERE LOWER(email) = :e',
            [':e' => $email]
        );
    }
    return array_map('intval', array_column($rows, 'traject_id'));
}

/**
 * Mag deze (huidige) user dit specifieke traject inzien?
 */
function can_view_traject(int $trajectId, ?array $user = null): bool {
    if (!can('traject.view', $user)) return false;
    $ids = user_allowed_traject_ids($user);
    if ($ids === null) return true; // ongescoped
    return in_array($trajectId, $ids, true);
}

/**
 * Combineert een capability met traject-scope — voorkomt dat een gescopete
 * rol write-acties uitvoert op een traject waaraan hij niet gekoppeld is.
 */
function can_edit_traject(string $capability, int $trajectId, ?array $user = null): bool {
    return can($capability, $user) && can_view_traject($trajectId, $user);
}

