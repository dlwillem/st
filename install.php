<?php
/**
 * In-app install-wizard — browser-only, geen token nodig.
 *
 * Lock (tweevoudige gate, beide gecheckt tegen DB):
 *   1. Er bestaat nog géén actieve admin in de users-tabel
 *   2. settings.installed_at is nog niet gezet
 *
 * Stappen: DB → schema → seed-data → admin → branding → mail → klaar.
 *
 * Na succesvolle installatie: VERWIJDER install.php en sql/ van de host.
 * De locks hierboven beschermen ook als dat vergeten wordt, maar verwijderen
 * is de veilige default.
 *
 * Deze file laadt NIET de gewone bootstrap (die verwacht al een werkende DB
 * en settings-tabel) maar laadt minimaal env + config zodat APP_ROOT/env()
 * beschikbaar zijn. Na DB-stap wordt een verse PDO opgebouwd.
 */

define('APP_BOOT', true);
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/config.php';

session_start();

// ── Helpers ────────────────────────────────────────────────────────────
function wiz_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function wiz_env_path(): string { return APP_ROOT . '/.env'; }

function wiz_env_read(): array {
    $p = wiz_env_path();
    if (!is_file($p)) return [];
    $out = [];
    foreach (file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line === '' || str_starts_with(ltrim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if (strlen($v) >= 2 && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'"))) {
            $v = substr($v, 1, -1);
        }
        $out[$k] = $v;
    }
    return $out;
}

function wiz_env_write(array $kv): void {
    $p = wiz_env_path();
    if (is_file($p) && !is_writable($p)) throw new RuntimeException('.env niet schrijfbaar.');
    if (!is_file($p) && !is_writable(dirname($p))) throw new RuntimeException('App-root niet schrijfbaar voor .env.');
    $cur = wiz_env_read();
    foreach ($kv as $k => $v) $cur[$k] = $v;
    $lines = [];
    foreach ($cur as $k => $v) {
        $needsQuote = preg_match('/[\s#"\']/', (string)$v);
        $lines[] = $k . '=' . ($needsQuote ? '"' . str_replace('"', '\\"', (string)$v) . '"' : (string)$v);
    }
    file_put_contents($p, implode("\n", $lines) . "\n", LOCK_EX);
    foreach ($kv as $k => $v) { putenv("$k=$v"); $_ENV[$k] = $v; }
}

function wiz_pdo(array $dbCfg): PDO {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $dbCfg['host'], $dbCfg['port'], $dbCfg['name']
    );
    return new PDO($dsn, $dbCfg['user'], $dbCfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

function wiz_try_pdo_from_env(): ?PDO {
    $host = env('DB_HOST'); $name = env('DB_NAME');
    $user = env('DB_USER'); $pass = env('DB_PASS');
    $port = env('DB_PORT', '3306');
    if (!$host || !$name || !$user) return null;
    try {
        return wiz_pdo(['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass]);
    } catch (Throwable $e) { return null; }
}

function wiz_schema_exec(PDO $pdo): void {
    $sql = @file_get_contents(APP_ROOT . '/sql/schema.sql');
    if ($sql === false || $sql === '') throw new RuntimeException('sql/schema.sql ontbreekt of is leeg.');
    // Verwijder /*!...*/-conditional-comments (mysqldump-set-client-encoding e.d.).
    $sql = preg_replace('~/\*!\d+.*?\*/;~s', '', $sql);
    // Split on ";" aan einde van regel — voldoende voor mysqldump-output.
    $stmts = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
    foreach ($stmts as $stmt) {
        if ($stmt === '' || str_starts_with($stmt, '--')) continue;
        $pdo->exec($stmt);
    }
    // Zorg dat settings-tabel bestaat (niet in legacy-dumps gegarandeerd).
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        `key` VARCHAR(64) NOT NULL PRIMARY KEY,
        `value` TEXT NOT NULL,
        `updated_at` DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function wiz_set_setting(PDO $pdo, string $key, string $value): void {
    $st = $pdo->prepare('INSERT INTO settings (`key`,`value`,`updated_at`) VALUES (:k,:v,NOW())
                         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), `updated_at`=NOW()');
    $st->execute([':k' => $key, ':v' => $value]);
}

function wiz_ensure_app_key(): string {
    $raw = env('APP_KEY');
    if ($raw) return $raw;
    $new = 'base64:' . base64_encode(random_bytes(32));
    wiz_env_write(['APP_KEY' => $new]);
    return $new;
}

function wiz_encrypt(string $plain): string {
    $raw = wiz_ensure_app_key();
    $key = str_starts_with($raw, 'base64:') ? base64_decode(substr($raw, 7), true) : null;
    if (!$key || strlen($key) !== 32) throw new RuntimeException('APP_KEY ongeldig.');
    $iv  = random_bytes(12); $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

// ── Lock check ─────────────────────────────────────────────────────────
// Auto-create minimale .env als hij nog ontbreekt (eerste upload).
if (!is_file(wiz_env_path())) {
    // Bouw APP_BASE_URL inclusief eventueel sub-pad waar de app onder draait.
    // SCRIPT_NAME is bv. "/st2/st/install.php" → strip "/install.php" zodat
    // de app-root over blijft. Bij een document-root-install is dit ''.
    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $appPath    = preg_replace('~/install\.php$~', '', $scriptName) ?? '';
    $appPath    = rtrim($appPath, '/');
    $scheme     = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
    $autoBase   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $appPath;
    @file_put_contents(wiz_env_path(),
        "APP_ENV=production\nAPP_BASE_URL=$autoBase\nAPP_KEY=\n", LOCK_EX);
    // Herlaad env zodat env() de nieuwe waarden ziet. We willen de loader-IIFE
    // wèl opnieuw draaien (om de zojuist weggeschreven .env in te lezen), maar
    // niet de env()-functie opnieuw definiëren — vandaar de guard in env.php.
    require __DIR__ . '/config/env.php';
}

$alreadyInstalled = false;
$hasAdmin         = false;
$pdoCheck = wiz_try_pdo_from_env();
if ($pdoCheck) {
    try {
        $row = $pdoCheck->query("SELECT `value` FROM settings WHERE `key`='installed_at'")->fetch();
        if ($row && !empty($row['value'])) $alreadyInstalled = true;
    } catch (Throwable $e) { /* settings-tabel bestaat nog niet */ }
    try {
        $row = $pdoCheck->query("SELECT COUNT(*) AS n FROM users WHERE role='architect' AND active=1")->fetch();
        if ($row && (int)$row['n'] > 0) $hasAdmin = true;
    } catch (Throwable $e) { /* users-tabel bestaat nog niet */ }
}

if ($alreadyInstalled || $hasAdmin) {
    http_response_code(410);
    echo wiz_layout('Installatie afgesloten', '
        <h2>Installatie afgesloten</h2>
        <p>De applicatie is al geïnstalleerd of er is reeds een admin-account.</p>
        <p><strong>Verwijder <code>install.php</code> en de map <code>sql/</code> van je host
           als je dat nog niet gedaan hebt.</strong></p>
        <p>Ga naar <a href="' . wiz_h(APP_BASE_URL) . '/pages/login.php">de login-pagina</a>.</p>
    ');
    exit;
}

// ── Router ─────────────────────────────────────────────────────────────
// Speciale GET-action: download lege demo-template (vóór step-router).
if (($_GET['action'] ?? '') === 'demo_template_download') {
    wiz_bootstrap_db();
    require_once APP_ROOT . '/includes/demo_seed.php';
    demo_template_xlsx('demo-template.xlsx');
}

$step = (int)($_GET['step'] ?? $_POST['step'] ?? 1);
$step = max(1, min(7, $step));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $step = wiz_handle_post($step);
        header('Location: install.php?step=' . $step);
        exit;
    } catch (Throwable $e) {
        $_SESSION['wiz_error'] = $e->getMessage();
    }
}

echo wiz_render($step);
exit;

// ── POST handlers ──────────────────────────────────────────────────────
function wiz_handle_post(int $step): int {
    $data = &$_SESSION['wiz'];
    $data = $data ?? [];

    if ($step === 1) {
        $cfg = [
            'host' => trim($_POST['db_host'] ?? ''),
            'port' => trim($_POST['db_port'] ?? '3306'),
            'name' => trim($_POST['db_name'] ?? ''),
            'user' => trim($_POST['db_user'] ?? ''),
            'pass' => (string)($_POST['db_pass'] ?? ''),
        ];
        foreach (['host','name','user'] as $k) if ($cfg[$k] === '') throw new RuntimeException('Vul alle DB-velden in.');
        $pdo = wiz_pdo($cfg);   // gooit PDOException bij fout
        // Minimaal schrijfrecht testen
        $pdo->query('SELECT 1');
        wiz_env_write([
            'DB_HOST' => $cfg['host'], 'DB_PORT' => $cfg['port'],
            'DB_NAME' => $cfg['name'], 'DB_USER' => $cfg['user'], 'DB_PASS' => $cfg['pass'],
        ]);
        wiz_ensure_app_key();
        return 2;
    }

    if ($step === 2) {
        $pdo = wiz_try_pdo_from_env();
        if (!$pdo) throw new RuntimeException('DB-verbinding verloren — ga terug naar stap 1.');
        wiz_schema_exec($pdo);
        return 3;
    }

    if ($step === 3) {
        $choice = $_POST['seed_choice'] ?? 'empty';
        if (!in_array($choice, ['empty', 'seed', 'seed_demo'], true)) {
            throw new RuntimeException('Ongeldige seed-keuze.');
        }

        if ($choice !== 'empty') {
            $structPath = APP_ROOT . '/data/seed/structuur.xlsx';
            if (!is_file($structPath)) {
                throw new RuntimeException('Seed-Excel ontbreekt: data/seed/structuur.xlsx.');
            }
            wiz_bootstrap_db();
            require_once APP_ROOT . '/includes/structure_import.php';
            structure_import_xlsx($structPath);
        }
        if ($choice === 'seed_demo') {
            $demoPath = APP_ROOT . '/data/seed/demo_compleet.xlsx';
            if (!is_file($demoPath)) {
                throw new RuntimeException('Demo-Excel ontbreekt: data/seed/demo_compleet.xlsx.');
            }
            require_once APP_ROOT . '/includes/demo_seed.php';
            demo_import_xlsx($demoPath);
        }
        return 4;
    }

    if ($step === 4) {
        $pdo = wiz_try_pdo_from_env();
        if (!$pdo) throw new RuntimeException('DB-verbinding verloren.');
        $name  = trim($_POST['name'] ?? '');
        $email = trim(mb_strtolower($_POST['email'] ?? ''));
        $pwd   = (string)($_POST['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Naam en geldig e-mailadres zijn verplicht.');
        if (mb_strlen($pwd) < 12) throw new RuntimeException('Wachtwoord moet minstens 12 tekens zijn.');
        $hash = password_hash($pwd, PASSWORD_DEFAULT);
        $st = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, active, created_at)
                             VALUES (:n,:e,:p,'architect',1,NOW())");
        $st->execute([':n'=>$name, ':e'=>$email, ':p'=>$hash]);
        return 5;
    }

    if ($step === 5) {
        $pdo = wiz_try_pdo_from_env();
        $app = trim($_POST['app_name'] ?? '');
        $co  = trim($_POST['company_name'] ?? '');
        if ($app === '') throw new RuntimeException('App-naam is verplicht.');
        wiz_set_setting($pdo, 'app_name', $app);
        wiz_set_setting($pdo, 'company_name', $co);
        return 6;
    }

    if ($step === 6) {
        $pdo = wiz_try_pdo_from_env();
        $driver = ($_POST['mail_driver'] ?? 'log') === 'smtp' ? 'smtp' : 'log';
        wiz_set_setting($pdo, 'mail_driver', $driver);
        wiz_set_setting($pdo, 'mail_from', trim($_POST['mail_from'] ?? ''));
        wiz_set_setting($pdo, 'mail_from_name', trim($_POST['mail_from_name'] ?? ''));
        if ($driver === 'smtp') {
            wiz_set_setting($pdo, 'smtp_host', trim($_POST['smtp_host'] ?? ''));
            wiz_set_setting($pdo, 'smtp_port', (string)max(1, (int)($_POST['smtp_port'] ?? 587)));
            wiz_set_setting($pdo, 'smtp_user', trim($_POST['smtp_user'] ?? ''));
            $sec = $_POST['smtp_secure'] ?? 'tls';
            wiz_set_setting($pdo, 'smtp_secure', in_array($sec, ['tls','ssl',''], true) ? $sec : 'tls');
            $pw = (string)($_POST['smtp_pass'] ?? '');
            if ($pw !== '') wiz_set_setting($pdo, 'smtp_pwd_enc', wiz_encrypt($pw));
        }
        wiz_set_setting($pdo, 'installed_at', date('c'));
        return 7;
    }

    return $step;
}

/**
 * Bootstrap minimaal de db()-helper + db_functions zodat structure_import_xlsx
 * en demo_import_xlsx kunnen draaien tijdens de wizard. Veilig om meerdere
 * keren te roepen — config/db.php wordt door require_once geguard.
 */
function wiz_bootstrap_db(): void {
    require_once APP_ROOT . '/config/db.php';
    require_once APP_ROOT . '/includes/db_functions.php';
}

// ── Render ─────────────────────────────────────────────────────────────
function wiz_render(int $step): string {
    $err = $_SESSION['wiz_error'] ?? null;
    unset($_SESSION['wiz_error']);
    $titles = [
        1 => 'Database', 2 => 'Schema', 3 => 'Seed-data',
        4 => 'Admin-account', 5 => 'Branding', 6 => 'Mail', 7 => 'Klaar',
    ];

    $steps = '';
    foreach ($titles as $i => $t) {
        $cls = $i < $step ? 'done' : ($i === $step ? 'cur' : '');
        $steps .= "<li class=\"$cls\"><span>$i</span> " . wiz_h($t) . '</li>';
    }

    ob_start(); ?>
    <form method="post" class="wiz-form" autocomplete="off">
      <input type="hidden" name="step" value="<?= $step ?>">
    <?php if ($err): ?>
      <div class="err">⚠ <?= wiz_h($err) ?></div>
    <?php endif; ?>
    <?php
    switch ($step) {
        case 1:
            $env = wiz_env_read();
            ?>
            <h2>1 · Database-verbinding</h2>
            <p class="muted">Vul de MySQL-gegevens in. Deze worden in <code>.env</code> opgeslagen.</p>
            <label>Host <input name="db_host" value="<?= wiz_h($env['DB_HOST'] ?? '127.0.0.1') ?>" required></label>
            <label>Poort <input name="db_port" value="<?= wiz_h($env['DB_PORT'] ?? '3306') ?>" required></label>
            <label>Database-naam <input name="db_name" value="<?= wiz_h($env['DB_NAME'] ?? '') ?>" required></label>
            <label>Gebruiker <input name="db_user" value="<?= wiz_h($env['DB_USER'] ?? '') ?>" required></label>
            <label>Wachtwoord <input type="password" name="db_pass" value=""></label>
            <button class="btn">Test & opslaan →</button>
            <?php break;
        case 2: ?>
            <h2>2 · Schema aanmaken</h2>
            <p class="muted">We maken alle tabellen aan via <code>sql/schema.sql</code>. Dit is <strong>destructief</strong> als er al tabellen met dezelfde namen bestaan — ze worden opnieuw gemaakt alleen als ze nog niet bestaan (CREATE TABLE zonder IF NOT EXISTS faalt).</p>
            <button class="btn">Schema installeren →</button>
            <?php break;
        case 3:
            $hasStruct = is_file(APP_ROOT . '/data/seed/structuur.xlsx');
            $hasDemo   = is_file(APP_ROOT . '/data/seed/demo_compleet.xlsx');
            ?>
            <h2>3 · Seed-data</h2>
            <p class="muted">Kies waarmee de database wordt gevuld. Je kunt dit niet meer ongedaan maken zonder een wipe via Instellingen.</p>

            <label style="font-weight:normal;display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;margin:8px 0;">
              <input type="radio" name="seed_choice" value="empty" checked style="width:auto;margin-top:3px;">
              <span><strong>Lege database</strong><br>
                <span class="muted small">Geen seed-data. Je beheert structuur later via Instellingen → Structuur.</span>
              </span>
            </label>

            <label style="font-weight:normal;display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;margin:8px 0;<?= $hasStruct ? '' : 'opacity:.55;' ?>">
              <input type="radio" name="seed_choice" value="seed" <?= $hasStruct ? '' : 'disabled' ?> style="width:auto;margin-top:3px;">
              <span><strong>Seed-data laden</strong><br>
                <span class="muted small">Laadt de structuur (App soorten, App services, NFR/VEND/IMPL/SUP/LIC subcats, DEMO-vragen) uit <code>data/seed/structuur.xlsx</code>.</span>
                <?php if (!$hasStruct): ?><br><span class="small" style="color:#991b1b;">Bestand ontbreekt: <code>data/seed/structuur.xlsx</code>.</span><?php endif; ?>
              </span>
            </label>

            <label style="font-weight:normal;display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;margin:8px 0;<?= ($hasStruct && $hasDemo) ? '' : 'opacity:.55;' ?>">
              <input type="radio" name="seed_choice" value="seed_demo" <?= ($hasStruct && $hasDemo) ? '' : 'disabled' ?> style="width:auto;margin-top:3px;">
              <span><strong>Seed-data + demo-data</strong><br>
                <span class="muted small">Bovenstaande, plus voorbeeldtrajecten + leveranciers + requirements uit <code>data/seed/demo_compleet.xlsx</code>.</span>
                <?php if (!$hasStruct): ?><br><span class="small" style="color:#991b1b;">Eerst <code>data/seed/structuur.xlsx</code> nodig.</span>
                <?php elseif (!$hasDemo): ?><br><span class="small" style="color:#991b1b;">Bestand ontbreekt: <code>data/seed/demo_compleet.xlsx</code>.</span>
                <?php endif; ?>
              </span>
            </label>

            <p class="muted small" style="margin-top:14px;">
              Een lege demo-template kun je nu downloaden om in te vullen voordat je deze stap voltooit:
              <a href="install.php?action=demo_template_download">demo-template.xlsx</a>.
              Plaats 'm daarna als <code>data/seed/demo_compleet.xlsx</code> en herlaad deze pagina.
            </p>

            <button class="btn">Volgende →</button>
            <?php break;
        case 4: ?>
            <h2>4 · Eerste admin-account</h2>
            <p class="muted">Deze gebruiker krijgt rol <code>architect</code> (volledige rechten).</p>
            <label>Naam <input name="name" required maxlength="150"></label>
            <label>E-mailadres <input type="email" name="email" required maxlength="190"></label>
            <label>Wachtwoord (min 12 tekens) <input type="password" name="password" required minlength="12"></label>
            <button class="btn">Account aanmaken →</button>
            <?php break;
        case 5: ?>
            <h2>5 · Branding</h2>
            <label>App-naam <input name="app_name" value="Selectie Tool" required maxlength="100"></label>
            <label>Bedrijfsnaam <span class="muted">(optioneel)</span> <input name="company_name" maxlength="100"></label>
            <p class="muted small">Logo kun je later uploaden via Instellingen → Branding.</p>
            <button class="btn">Opslaan →</button>
            <?php break;
        case 6: ?>
            <h2>6 · Mail</h2>
            <p class="muted small">Kies <code>log</code> voor een droge installatie — berichten worden dan naar <code>logs/mail.log</code> geschreven. SMTP-instellingen kun je altijd later aanvullen.</p>
            <label>Driver
              <select name="mail_driver" onchange="document.getElementById('smtp').style.display=this.value==='smtp'?'':'none'">
                <option value="log">Log (geen echte mail)</option>
                <option value="smtp">SMTP</option>
              </select>
            </label>
            <label>Afzender-naam <input name="mail_from_name" value="Selectie Tool" maxlength="100"></label>
            <label>Afzender-e-mail <input type="email" name="mail_from" maxlength="190"></label>
            <div id="smtp" style="display:none;">
              <label>SMTP-host <input name="smtp_host" maxlength="190"></label>
              <label>Poort <input name="smtp_port" value="587" type="number" min="1" max="65535"></label>
              <label>Beveiliging
                <select name="smtp_secure">
                  <option value="tls" selected>TLS</option>
                  <option value="ssl">SSL</option>
                  <option value="">Geen</option>
                </select>
              </label>
              <label>SMTP-gebruiker <input name="smtp_user" maxlength="190" autocomplete="off"></label>
              <label>SMTP-wachtwoord <input type="password" name="smtp_pass" autocomplete="new-password"></label>
            </div>
            <button class="btn">Installatie afronden →</button>
            <?php break;
        case 7: ?>
            <h2>✔ Klaar — installatie succesvol</h2>
            <div class="done-cleanup">
              <strong>⚠ Belangrijk — verwijder nu van de host:</strong>
              <ul>
                <li><code>install.php</code> (deze wizard)</li>
                <li>map <code>sql/</code> (schema-dump, alleen nodig tijdens install)</li>
              </ul>
              <p>Ook als je het vergeet blijft de wizard op slot (via de admin- en
                 <code>installed_at</code>-check), maar verwijderen is veiliger.</p>
            </div>
            <p>Volgende stappen:</p>
            <ol>
              <li>Controleer dat <code>APP_ENV=production</code> in <code>.env</code> staat.</li>
              <li>Log in op <a href="<?= wiz_h(APP_BASE_URL) ?>/pages/login.php">de login-pagina</a>.</li>
              <li>Ga naar Instellingen → Structuur om een Excel-template te uploaden (optioneel).</li>
            </ol>
            <?php break;
    } ?>
    </form>
    <?php
    $body = '<ol class="wiz-steps">' . $steps . '</ol>' . ob_get_clean();
    return wiz_layout('Installatie — stap ' . $step, $body);
}

function wiz_layout(string $title, string $body): string {
    $t = wiz_h($title);
    return <<<HTML
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>$t</title>
  <style>
    body { font: 14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif; background:#f5f5f7; margin:0; padding:40px 16px; }
    .wrap { max-width:640px; margin:0 auto; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:28px 32px; }
    h1 { margin:0 0 8px; font-size:22px; }
    h2 { margin:20px 0 10px; font-size:18px; }
    p, label { display:block; }
    label { margin:10px 0; font-weight:600; }
    input, select { display:block; width:100%; box-sizing:border-box; padding:8px 10px; margin-top:4px;
                    border:1px solid #d1d5db; border-radius:6px; font:inherit; font-weight:normal; }
    .btn { background:#ec4899; color:#fff; border:0; padding:10px 18px; border-radius:8px;
           font:inherit; font-weight:600; cursor:pointer; margin-top:14px; }
    .btn:hover { background:#db2777; }
    .muted { color:#6b7280; }
    .small { font-size:12px; }
    pre, code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    pre { background:#f3f4f6; padding:10px 12px; border-radius:6px; overflow:auto; }
    .err { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:10px 12px; border-radius:6px; margin-bottom:14px; }
    .done-cleanup { background:#fef2f2; color:#991b1b; border:1.5px solid #fecaca; padding:14px 16px; border-radius:8px; margin:14px 0 18px; }
    .done-cleanup strong { display:block; margin-bottom:6px; font-size:15px; }
    .done-cleanup ul { margin:6px 0 8px 20px; padding:0; }
    .done-cleanup p { margin:8px 0 0; font-size:12.5px; color:#7f1d1d; }
    .wiz-steps { list-style:none; padding:0; margin:0 0 24px; display:flex; gap:6px; flex-wrap:wrap; font-size:12px; }
    .wiz-steps li { padding:6px 10px; border-radius:999px; background:#f3f4f6; color:#6b7280; }
    .wiz-steps li span { display:inline-block; width:18px; height:18px; line-height:18px; text-align:center;
                          background:#e5e7eb; color:#374151; border-radius:50%; margin-right:6px; font-weight:700; }
    .wiz-steps li.cur  { background:#fce7f3; color:#9d174d; }
    .wiz-steps li.cur  span { background:#ec4899; color:#fff; }
    .wiz-steps li.done { background:#dcfce7; color:#166534; }
    .wiz-steps li.done span { background:#16a34a; color:#fff; }
  </style>
</head>
<body><div class="wrap"><h1>Selectie Tool — Installatie</h1>$body</div></body>
</html>
HTML;
}
