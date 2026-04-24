<?php
/**
 * Sessiebeheer en rolcontrole.
 * Volledige login-flow komt in Prompt 2; hier de basis zodat andere bestanden
 * er nu al van afhankelijk kunnen zijn.
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

function session_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_start();

    // Binding aan IP + user-agent om sessiekaping te bemoeilijken
    $fingerprint = hash('sha256',
        ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (empty($_SESSION['fp'])) {
        $_SESSION['fp'] = $fingerprint;
    } elseif (!hash_equals($_SESSION['fp'], $fingerprint)) {
        session_unset();
        session_destroy();
        return;
    }

    // Periodieke session-ID regeneratie
    if (empty($_SESSION['sid_issued'])) {
        $_SESSION['sid_issued'] = time();
    } elseif (time() - $_SESSION['sid_issued'] > SESSION_ID_TTL) {
        session_regenerate_id(true);
        $_SESSION['sid_issued'] = time();
    }
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool {
    return !empty($_SESSION['user']['id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        redirect('pages/login.php');
    }
}

// ─── Login / logout ──────────────────────────────────────────────────────────

/**
 * Registreer een loginpoging (voor rate-limit).
 */
function login_record_attempt(string $email, bool $success): void {
    try {
        db_insert('login_attempts', [
            'email'        => mb_substr($email, 0, 190),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '',
            'success'      => $success ? 1 : 0,
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        error_log('login_record_attempt failed: ' . $e->getMessage());
    }
}

/**
 * Aantal mislukte pogingen in het lockout-venster per email+IP.
 */
function login_failed_count(string $email): int {
    $since = date('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_SEC);
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '';
    return (int)db_value(
        'SELECT COUNT(*) FROM login_attempts
         WHERE success = 0 AND attempted_at > :since
           AND (email = :e OR ip_address = :ip)',
        [':since' => $since, ':e' => $email, ':ip' => $ip]
    );
}

function login_is_locked_out(string $email): bool {
    return login_failed_count($email) >= LOGIN_MAX_ATTEMPTS;
}

/**
 * Probeer in te loggen. Geeft user-row terug bij succes, null bij falen.
 * Doet zelf de attempt-registratie en audit-log.
 */
function login_attempt(string $email, string $password): ?array {
    $email = trim(mb_strtolower($email));
    $user  = db_one('SELECT * FROM users WHERE email = :e AND active = 1', [':e' => $email]);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        login_record_attempt($email, false);
        // Alleen auditloggen als de user bestaat — anders vult elke typo/brute-force
        // het audit-log en wordt enumeration mogelijk via die log-export.
        if ($user) {
            audit_log('login_failed', 'user', (int)$user['id'], $email);
        }
        return null;
    }

    // Eventueel rehash bij nieuwe cost-factor
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
        db_update('users',
            ['password_hash' => password_hash($password, PASSWORD_BCRYPT)],
            'id = :id', [':id' => $user['id']]);
    }

    login_record_attempt($email, true);
    login_set_user($user);
    db_update('users', ['last_login' => date('Y-m-d H:i:s')],
              'id = :id', [':id' => $user['id']]);
    audit_log('login', 'user', (int)$user['id'], $user['name']);
    return $user;
}

/**
 * Zet de user in de sessie na succesvolle authenticatie.
 */
function login_set_user(array $user): void {
    // Regenereer session-ID om fixatie te voorkomen
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'    => (int)$user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ];
    $_SESSION['sid_issued'] = time();
}

function logout_user(): void {
    $user = current_user();
    if ($user) audit_log('logout', 'user', (int)$user['id'], $user['name']);

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
                  $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ─── Password reset ──────────────────────────────────────────────────────────

/**
 * Maak een reset-token aan voor een user en retourneer het klare-text token.
 * In DB bewaren we alleen de SHA-256 hash.
 */
function password_reset_create(int $userId, int $ttlMinutes = 60): string {
    $rawToken = bin2hex(random_bytes(32));
    db_insert('password_resets', [
        'user_id'    => $userId,
        'token_hash' => hash('sha256', $rawToken),
        'expires_at' => date('Y-m-d H:i:s', time() + $ttlMinutes * 60),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    return $rawToken;
}

function password_reset_find(string $rawToken): ?array {
    return db_one(
        'SELECT pr.*, u.email, u.name
           FROM password_resets pr
           JOIN users u ON u.id = pr.user_id
          WHERE pr.token_hash = :h
            AND pr.used_at IS NULL
            AND pr.expires_at > NOW()',
        [':h' => hash('sha256', $rawToken)]
    );
}

function password_reset_consume(int $resetId, int $userId, string $newPassword): void {
    db_transaction(function () use ($resetId, $userId, $newPassword) {
        db_update('users',
            ['password_hash' => password_hash($newPassword, PASSWORD_BCRYPT)],
            'id = :id', [':id' => $userId]);
        db_update('password_resets',
            ['used_at' => date('Y-m-d H:i:s')],
            'id = :id', [':id' => $resetId]);
        // invalideer overige openstaande tokens voor deze user
        db_exec('UPDATE password_resets SET used_at = NOW()
                 WHERE user_id = :u AND used_at IS NULL AND id <> :id',
                [':u' => $userId, ':id' => $resetId]);
    });
}
