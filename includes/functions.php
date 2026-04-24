<?php
/**
 * Algemene helperfuncties — CSRF, sanitization, UUID, audit, mail-stub.
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

// ─── Output escaping ──────────────────────────────────────────────────────────
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─── Icons (Lucide subset als inline SVG) ────────────────────────────────────
function icon(string $name, int $size = 16, string $extraClass = ''): string {
    static $paths = [
        'home'         => '<path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7h-6v7H4a1 1 0 0 1-1-1V9.5Z"/>',
        'folder'       => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/>',
        'clipboard'    => '<rect x="8" y="3" width="8" height="4" rx="1"/><path d="M8 5H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>',
        'users'        => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'settings'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
        'file-text'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
        'activity'     => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'log-out'      => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'bell'         => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'chevron-down' => '<polyline points="6 9 12 15 18 9"/>',
        'search'       => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'plus'         => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'trash'        => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>',
        'edit'         => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4Z"/>',
        'x'            => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'check'        => '<polyline points="20 6 9 17 4 12"/>',
        'user'         => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'package'      => '<line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
        'sliders'      => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'download'     => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'upload'       => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'layers'       => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        'refresh'      => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10"/><path d="M20.49 15a9 9 0 0 1-14.85 3.36L1 14"/>',
        'info'         => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'mail'         => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'monitor'      => '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
    ];
    $path = $paths[$name] ?? '';
    $cls  = trim('icon ' . $extraClass);
    return sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" class="%s" width="%d" height="%d" '
      . 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
      . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
        h($cls), $size, $size, $path
    );
}

function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    if (!$parts || $parts[0] === '') return '?';
    $first = mb_substr($parts[0], 0, 1);
    $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

// ─── CSRF ─────────────────────────────────────────────────────────────────────
function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function csrf_check(?string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_require(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!csrf_check($token)) {
        http_response_code(419);
        exit('CSRF-token ongeldig.');
    }
}

// ─── Input helpers ────────────────────────────────────────────────────────────
function input(string $key, $default = null) {
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function input_int(string $key, ?int $default = null): ?int {
    $v = input($key);
    return $v === null || $v === '' ? $default : (int)$v;
}

function input_str(string $key, string $default = ''): string {
    $v = input($key, $default);
    return is_string($v) ? trim($v) : $default;
}

// ─── JSON response helper ─────────────────────────────────────────────────────
function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ─── Flash messages ───────────────────────────────────────────────────────────
function flash_set(string $type, string $message): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_pull(): array {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/**
 * Sanitize een door leverancier/user aangeleverde URL voor gebruik in <a href>.
 * Staat alleen http(s):// toe; `javascript:`, `data:`, `vbscript:` en andere
 * schemes worden geblokkeerd (XSS-mitigatie).
 */
function safe_url(?string $url): string {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (!preg_match('#^https?://#i', $url)) return '';
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return '';
    return $url;
}

// ─── Redirect ─────────────────────────────────────────────────────────────────
/**
 * Veilige redirect — staat alleen interne paden toe, of absolute URL's waarvan
 * de host overeenkomt met APP_BASE_URL. Voorkomt open-redirect via user-input.
 */
function redirect(string $path): void {
    $base = APP_BASE_URL;
    if (preg_match('#^https?://#i', $path)) {
        $targetHost = parse_url($path, PHP_URL_HOST);
        $baseHost   = parse_url($base, PHP_URL_HOST);
        if ($targetHost !== $baseHost) {
            // Vreemde host — negeer de user-input en val terug op de app-home.
            $url = $base . '/';
        } else {
            $url = $path;
        }
    } else {
        $url = $base . '/' . ltrim($path, '/');
    }
    header('Location: ' . $url);
    exit;
}

// ─── Audit log ────────────────────────────────────────────────────────────────
function audit_log(string $action, string $entityType = '', ?int $entityId = null, string $detail = ''): void {
    try {
        $user = $_SESSION['user'] ?? null;
        $stmt = db()->prepare(
            'INSERT INTO audit_log (user_id, user_name, action, entity_type, entity_id, detail, ip_address, created_at)
             VALUES (:uid, :uname, :action, :etype, :eid, :detail, :ip, NOW())'
        );
        $stmt->execute([
            ':uid'    => $user['id']   ?? null,
            ':uname'  => $user['name'] ?? 'systeem',
            ':action' => $action,
            ':etype'  => $entityType,
            ':eid'    => $entityId,
            ':detail' => $detail,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (Throwable $e) {
        error_log('audit_log failed: ' . $e->getMessage());
    }
}

// ─── Mail-stub ────────────────────────────────────────────────────────────────
function mail_config(): array {
    $driver   = setting_get('mail_driver');   if ($driver   === '') $driver   = defined('MAIL_DRIVER') ? MAIL_DRIVER : 'log';
    $from     = setting_get('mail_from');     if ($from     === '') $from     = defined('MAIL_FROM') ? MAIL_FROM : '';
    $fromName = setting_get('mail_from_name');if ($fromName === '') $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : setting_app_name();
    $host     = setting_get('smtp_host');     if ($host     === '' && defined('SMTP_HOST'))   $host = SMTP_HOST;
    $port     = (int)setting_get('smtp_port');if ($port     === 0  && defined('SMTP_PORT'))   $port = (int)SMTP_PORT;
    $user     = setting_get('smtp_user');     if ($user     === '' && defined('SMTP_USER'))   $user = SMTP_USER;
    $secure   = setting_get('smtp_secure');   if ($secure   === '' && defined('SMTP_SECURE')) $secure = SMTP_SECURE;
    $pwdEnc   = setting_get('smtp_pwd_enc');
    $pwd      = $pwdEnc !== '' ? (crypto_decrypt($pwdEnc) ?? '') : (defined('SMTP_PASS') ? SMTP_PASS : '');
    return compact('driver','from','fromName','host','port','user','secure','pwd');
}

function send_mail(string $to, string $toName, string $subject, string $htmlBody, string $textBody = ''): bool {
    $cfg = mail_config();
    if ($cfg['driver'] === 'log') {
        $masked = preg_replace(
            '/(token=)([A-Za-z0-9_\-]{6,})/',
            '$1***MASKED***',
            strip_tags($htmlBody)
        );
        $line = sprintf(
            "[%s] TO=%s <%s>  SUBJECT=%s\n---\n%s\n===\n",
            date('Y-m-d H:i:s'), $toName, $to, $subject, $masked
        );
        @file_put_contents(APP_ROOT . '/logs/mail.log', $line, FILE_APPEND);
        return true;
    }

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        error_log('PHPMailer niet geïnstalleerd.');
        return false;
    }
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->Port       = $cfg['port'] ?: 587;
        $mail->SMTPAuth   = $cfg['user'] !== '';
        $mail->Username   = $cfg['user'];
        $mail->Password   = $cfg['pwd'];
        if ($cfg['secure'] !== '') $mail->SMTPSecure = $cfg['secure'];
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($cfg['from'], $cfg['fromName']);
        $mail->addAddress($to, $toName);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);
        return $mail->send();
    } catch (Throwable $e) {
        error_log('send_mail failed: ' . $e->getMessage());
        return false;
    }
}
