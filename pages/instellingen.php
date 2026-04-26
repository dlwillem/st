<?php
/**
 * Instellingen — gebruikersbeheer (architect-only) en SMTP-info (read-only).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/users.php';
require_once __DIR__ . '/../includes/structure_export.php';
require_once __DIR__ . '/../includes/structure_import.php';
require_can('users.edit');

// ─── Structuur download (GET, geen CSRF nodig want leest alleen) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && input_str('action') === 'structure_download') {
    $mode = input_str('mode') === 'template' ? 'template' : 'current';
    $suffix = $mode === 'template' ? 'template' : date('Y-m-d');
    structure_export_xlsx($mode, 'structuur_' . $suffix . '.xlsx');
}

// ─── POST ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = input_str('action');
    try {
        if ($action === 'create') {
            user_create(
                input_str('name'),
                input_str('email'),
                input_str('role'),
                (string)($_POST['password'] ?? '')
            );
            flash_set('success', 'Gebruiker aangemaakt.');
        } elseif ($action === 'branding_save') {
            $appName = trim(input_str('app_name'));
            $company = trim(input_str('company_name'));
            if ($appName === '') throw new RuntimeException('App-naam is verplicht.');
            if (mb_strlen($appName) > 100) throw new RuntimeException('App-naam te lang (max 100).');
            if (mb_strlen($company) > 100) throw new RuntimeException('Bedrijfsnaam te lang (max 100).');
            setting_set('app_name', $appName);
            setting_set('company_name', $company);
            audit_log('settings.branding_save', 'settings', 0, 'app_name+company_name');
            flash_set('success', 'Branding opgeslagen.');
        } elseif ($action === 'branding_logo_upload') {
            if (empty($_FILES['logo']) || ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Geen bestand ontvangen of upload-fout.');
            }
            $f = $_FILES['logo'];
            if ((int)$f['size'] > 512 * 1024) throw new RuntimeException('Logo mag max 512 KB zijn.');
            $allowed = [
                'image/png'      => 'png',
                'image/jpeg'     => 'jpg',
                'image/svg+xml'  => 'svg',
            ];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
            if (!isset($allowed[$mime])) throw new RuntimeException('Alleen PNG, JPG of SVG toegestaan.');
            $ext = $allowed[$mime];
            $dir = APP_ROOT . '/uploads/branding';
            if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
                throw new RuntimeException('Kon upload-map niet aanmaken.');
            }
            $name = 'logo_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = $dir . '/' . $name;
            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                throw new RuntimeException('Upload mislukt.');
            }
            // Verwijder eventueel vorig logo
            $prev = setting_get('logo_path');
            if ($prev !== '' && str_starts_with($prev, 'uploads/branding/')) {
                $prevAbs = APP_ROOT . '/' . $prev;
                if (is_file($prevAbs)) @unlink($prevAbs);
            }
            setting_set('logo_path', 'uploads/branding/' . $name);
            audit_log('settings.logo_upload', 'settings', 0, $name);
            flash_set('success', 'Logo geüpload.');
        } elseif ($action === 'structure_upload') {
            if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Geen bestand ontvangen of upload-fout.');
            }
            $f = $_FILES['file'];
            if ((int)$f['size'] > 4 * 1024 * 1024) throw new RuntimeException('Bestand mag max 4 MB zijn.');
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if ($ext !== 'xlsx') throw new RuntimeException('Alleen .xlsx toegestaan.');
            $res = structure_import_xlsx($f['tmp_name']);
            audit_log('settings.structure_import', 'settings', 0,
                "cat={$res['cat']} app={$res['app']} sub={$res['sub']} demo={$res['demo']}");
            flash_set('success', sprintf(
                'Structuur geïmporteerd: %d categorieën, %d applicatiesoorten, %d subcategorieën, %d DEMO-vragen.',
                $res['cat'], $res['app'], $res['sub'], $res['demo']
            ));
        } elseif ($action === 'structure_wipe') {
            if (input_str('confirm') !== 'WIPE') {
                throw new RuntimeException('Typ WIPE ter bevestiging.');
            }
            structure_wipe();
            audit_log('settings.structure_wipe', 'settings', 0, 'structure_cleared');
            flash_set('success', 'Structuur gewist.');
        } elseif ($action === 'mail_save') {
            $driver   = input_str('mail_driver') === 'smtp' ? 'smtp' : 'log';
            $from     = trim(input_str('mail_from'));
            $fromName = trim(input_str('mail_from_name'));
            $host     = trim(input_str('smtp_host'));
            $port     = (int)input_str('smtp_port'); if ($port <= 0 || $port > 65535) $port = 587;
            $user     = trim(input_str('smtp_user'));
            $secure   = input_str('smtp_secure');
            if (!in_array($secure, ['tls','ssl',''], true)) $secure = 'tls';
            $pwdRaw   = (string)($_POST['smtp_pass'] ?? '');
            if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Ongeldig afzender-e-mailadres.');
            }
            setting_set('mail_driver', $driver);
            setting_set('mail_from', $from);
            setting_set('mail_from_name', $fromName);
            setting_set('smtp_host', $host);
            setting_set('smtp_port', (string)$port);
            setting_set('smtp_user', $user);
            setting_set('smtp_secure', $secure);
            if ($pwdRaw !== '') {
                $enc = crypto_encrypt($pwdRaw);
                if ($enc === '') throw new RuntimeException('Kon wachtwoord niet versleutelen (APP_KEY ontbreekt).');
                setting_set('smtp_pwd_enc', $enc);
            } elseif (input_str('clear_pass') === '1') {
                setting_set('smtp_pwd_enc', '');
            }
            audit_log('settings.mail_save', 'settings', 0, $driver);
            flash_set('success', 'Mail-instellingen opgeslagen.');
        } elseif ($action === 'mail_test') {
            $me = current_user();
            if (!$me || empty($me['email'])) throw new RuntimeException('Geen e-mailadres voor test.');
            $ok = send_mail(
                (string)$me['email'], (string)($me['name'] ?? 'Test'),
                setting_app_name() . ' — testmail',
                '<p>Dit is een testbericht vanuit ' . h(setting_app_name()) . '.</p>'
                . '<p>Verstuurd op ' . h(date('d-m-Y H:i:s')) . '.</p>'
            );
            if ($ok) flash_set('success', 'Testmail verzonden (driver: ' . mail_config()['driver'] . ').');
            else     flash_set('error', 'Testmail mislukt — zie logs.');
        } elseif ($action === 'branding_logo_remove') {
            $prev = setting_get('logo_path');
            if ($prev !== '' && str_starts_with($prev, 'uploads/branding/')) {
                $prevAbs = APP_ROOT . '/' . $prev;
                if (is_file($prevAbs)) @unlink($prevAbs);
            }
            setting_set('logo_path', '');
            audit_log('settings.logo_remove', 'settings', 0, '');
            flash_set('success', 'Logo verwijderd.');
        } elseif ($action === 'branding_favicon_upload') {
            if (empty($_FILES['favicon']) || ($_FILES['favicon']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Geen bestand ontvangen of upload-fout.');
            }
            $f = $_FILES['favicon'];
            if ((int)$f['size'] > 128 * 1024) throw new RuntimeException('Favicon mag max 128 KB zijn.');
            $allowed = [
                'image/x-icon'          => 'ico',
                'image/vnd.microsoft.icon' => 'ico',
                'image/png'             => 'png',
                'image/svg+xml'         => 'svg',
            ];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
            if (!isset($allowed[$mime])) throw new RuntimeException('Alleen ICO, PNG of SVG toegestaan.');
            $ext = $allowed[$mime];
            $dir = APP_ROOT . '/uploads/branding';
            if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
                throw new RuntimeException('Kon upload-map niet aanmaken.');
            }
            $name = 'favicon_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = $dir . '/' . $name;
            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                throw new RuntimeException('Upload mislukt.');
            }
            // Verwijder eventueel vorige favicon
            $prev = setting_get('favicon_path');
            if ($prev !== '' && str_starts_with($prev, 'uploads/branding/')) {
                $prevAbs = APP_ROOT . '/' . $prev;
                if (is_file($prevAbs)) @unlink($prevAbs);
            }
            setting_set('favicon_path', 'uploads/branding/' . $name);
            audit_log('settings.favicon_upload', 'settings', 0, $name);
            flash_set('success', 'Favicon geüpload.');
        } elseif ($action === 'branding_favicon_remove') {
            $prev = setting_get('favicon_path');
            if ($prev !== '' && str_starts_with($prev, 'uploads/branding/')) {
                $prevAbs = APP_ROOT . '/' . $prev;
                if (is_file($prevAbs)) @unlink($prevAbs);
            }
            setting_set('favicon_path', '');
            audit_log('settings.favicon_remove', 'settings', 0, '');
            flash_set('success', 'Favicon verwijderd.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect('pages/instellingen.php');
}

// Tabs: branding, users, mail, structure, app — default 'users'
$tab = input_str('tab');
if (!in_array($tab, ['branding', 'users', 'mail', 'structure', 'app'], true)) $tab = 'users';

$users = users_list();

$pageTitle  = 'Instellingen';
$currentNav = 'instellingen';

$bodyRenderer = function () use ($users, $tab) { ?>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;500;600;700;800&display=swap');
  .repo-screen { --pri:#0891b2;--pri-h:#0e7490;--grn:#10b981;--txt:#0d2d3a;--muted:#6b7280;--border:#e2e6ea;--bg:#f4f6f8;--card:#ffffff;--r:12px;--r-sm:6px;--sh:0 1px 3px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.04);--sh-h:0 6px 20px rgba(0,0,0,.1),0 16px 40px rgba(0,0,0,.07);font-family:'Nunito Sans',system-ui,sans-serif;color:var(--txt); }
  .repo-screen h1, .repo-screen h2, .repo-screen h3, .repo-screen p { font-family:inherit; }
  .repo-screen .page-header { margin-bottom:14px; }
  .repo-screen .page-header h1 { margin:0;font-size:22px;font-weight:700; }
  .repo-screen .page-header p { margin:2px 0 0;color:var(--muted);font-size:13px; }

  .repo-screen .cpill { padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase; }
  .repo-screen .cpill.cyan   { background:rgba(8,145,178,.10);color:#0891b2; }
  .repo-screen .cpill.indigo { background:rgba(99,102,241,.10);color:#4f46e5; }
  .repo-screen .cpill.blue   { background:rgba(59,130,246,.10);color:#2563eb; }
  .repo-screen .cpill.amber  { background:rgba(245,158,11,.10);color:#b45309; }
  .repo-screen .cpill.green  { background:rgba(16,185,129,.10);color:#059669; }
  .repo-screen .cpill.gray   { background:#f3f4f6;color:#6b7280; }
  .repo-screen .cpill.red    { background:rgba(239,68,68,.10);color:#dc2626; }

  .repo-card { background:var(--card);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--sh);margin-bottom:18px; }
  .repo-tabs { display:flex;gap:2px;padding:6px 10px 0;border-bottom:1px solid var(--border);background:#fff;flex-wrap:wrap;border-radius:var(--r) var(--r) 0 0; }
  .repo-tabs a { display:inline-flex;align-items:center;gap:6px;padding:7px 10px;margin-bottom:-1px;font-size:12px;font-weight:600;text-decoration:none;color:var(--muted);border-bottom:2px solid transparent;border-radius:6px 6px 0 0;transition:color .15s,border-color .15s,background .15s; }
  .repo-tabs a:hover { color:var(--txt);background:rgba(0,0,0,.025); }
  .repo-tabs a.active { color:var(--pri);border-bottom-color:var(--pri);background:rgba(8,145,178,.05); }

  .repo-card-head { padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--border);flex-wrap:wrap; }
  .repo-card-head .count { color:var(--muted);font-size:13px;font-weight:600; }
  .repo-card-body { padding:0; }
  .repo-section { padding:18px;border-bottom:1px solid var(--border); }
  .repo-section:last-child { border-bottom:0; }
  .repo-section h3 { margin:0 0 10px;font-size:14px;font-weight:700; }
  .repo-section .lead { color:var(--muted);font-size:13px;margin:0 0 12px; }

  .dt { width:100%;border-collapse:collapse;font-size:13.5px; }
  .dt th { font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);text-align:left;padding:10px 18px;background:#fafbfc;border-bottom:1px solid var(--border); }
  .dt td { padding:11px 18px;border-bottom:1px solid var(--border);vertical-align:middle; }
  .dt tr:last-child td { border-bottom:0; }
  .dt tr.row-link { cursor:pointer; }
  .dt tr.row-link:hover td { background:#f9fafb; }
  .dt .name { font-weight:600;color:var(--txt); }
  .dt .muted { color:var(--muted); }
  .dt .empty { padding:30px 18px;text-align:center;color:var(--muted);font-style:italic; }
  .dt .ck { color:var(--grn);font-weight:800; }
  .dt .nope { color:#cbd5e1; }

  .rb { display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:var(--r-sm);font-size:13px;font-weight:600;border:1px solid transparent;cursor:pointer;text-decoration:none;background:transparent;color:var(--txt);transition:background .15s,border-color .15s,color .15s; }
  .rb:hover { background:rgba(0,0,0,.04); }
  .rb.primary { background:var(--pri);color:#fff;border-color:var(--pri); }
  .rb.primary:hover { background:var(--pri-h);border-color:var(--pri-h); }
  .rb.ghost { border-color:var(--border); }
  .rb.danger { color:#dc2626; }
  .rb.danger:hover { background:rgba(220,38,38,.08); }
  .rb.dangerf { background:#dc2626;color:#fff;border-color:#dc2626; }
  .rb.dangerf:hover { background:#b91c1c;border-color:#b91c1c; }
  .rb.sm { padding:5px 9px;font-size:12px; }
  .rb[disabled] { opacity:.4;cursor:not-allowed; }

  .repo-search { display:flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--border);border-radius:var(--r-sm);padding:6px 10px;font-size:13px;min-width:240px; }
  .repo-search input { border:0;outline:0;flex:1;font:inherit;color:var(--txt);background:transparent; }
  .repo-search svg { color:var(--muted); }

  .fl { display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:14px 0 6px; }
  .fl:first-child { margin-top:0; }
  .fi, .fta { width:100%;border:1px solid var(--border);border-radius:var(--r-sm);padding:9px 12px;font:inherit;color:var(--txt);background:#fff;box-sizing:border-box; }
  .fi:focus, .fta:focus { outline:0;border-color:var(--pri); }
  .fta { resize:vertical;min-height:80px; }
  .fi.sel-sm { padding:6px 10px;font-size:12.5px;width:auto;min-width:140px; }
  .fi-row { display:flex;gap:14px;flex-wrap:wrap; }
  .fi-row > .fcol { flex:1;min-width:220px; }
  .fi-row > .fcol-sm { flex:0 0 140px; }

  .dropzone { border:1px dashed var(--border);border-radius:var(--r-sm);padding:18px;display:flex;align-items:center;justify-content:center;min-height:120px;background:#f9fafb; }
  .dropzone.empty { color:var(--muted);font-size:13px; }

  .rmodal-bg { position:fixed;inset:0;background:rgba(13,45,58,.45);display:none;align-items:center;justify-content:center;z-index:1000;padding:20px; }
  .rmodal-bg.open { display:flex; }
  .rmodal { background:#fff;border-radius:var(--r);box-shadow:var(--sh-h);width:520px;max-width:100%;padding:28px;font-family:'Nunito Sans',system-ui,sans-serif;color:var(--txt); }
  .rmodal h3 { margin:0 0 18px;font-size:18px;font-weight:700; }
  .rmodal .actions { display:flex;justify-content:flex-end;gap:10px;margin-top:22px; }
</style>

<div class="repo-screen">
  <div class="page-header">
    <h1>Instellingen</h1>
    <p>Gebruikersbeheer en systeemconfiguratie.</p>
  </div>

  <?php
    $tabs = [
      'branding'  => ['title' => 'Branding',                      'pill' => 'cyan'],
      'users'     => ['title' => 'Gebruikers en autorisatiematrix', 'pill' => 'indigo'],
      'mail'      => ['title' => 'Mail-configuratie',             'pill' => 'amber'],
      'structure' => ['title' => 'Structuur',                     'pill' => 'green'],
      'app'       => ['title' => 'Applicatie',                    'pill' => 'gray'],
    ];
  ?>

  <div class="repo-card">
    <nav class="repo-tabs">
      <?php foreach ($tabs as $code => $m): $active = ($code === $tab); ?>
        <a href="<?= h(APP_BASE_URL) ?>/pages/instellingen.php?tab=<?= h($code) ?>"
           class="<?= $active ? 'active' : '' ?>">
          <span class="cpill <?= h($m['pill']) ?>"><?= h(strtoupper($code)) ?></span>
          — <?= h($m['title']) ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if ($tab === 'branding'): ?>
      <?php
        $bAppName    = setting_get('app_name');
        $bCompany    = setting_get('company_name');
        $bLogoUrl    = setting_logo_url();
        $bFaviconUrl = setting_favicon_url();
      ?>
      <div class="repo-card-head">
        <div style="color:var(--muted);font-size:12.5px;">App-naam, bedrijfsnaam, logo en favicon.</div>
      </div>
      <div class="repo-card-body">
        <div class="repo-section">
          <h3>App- en bedrijfsnaam</h3>
          <p class="lead">De app-naam verschijnt in de titel, header en login-scherm. De bedrijfsnaam wordt in de zijbalk getoond als aanvulling.</p>
          <form method="post" autocomplete="off" style="max-width:520px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="branding_save">
            <label class="fl">App-naam</label>
            <input type="text" class="fi" name="app_name" maxlength="100" required value="<?= h($bAppName) ?>">
            <label class="fl">Bedrijfsnaam <span style="text-transform:none;font-weight:500;color:var(--muted);">(optioneel)</span></label>
            <input type="text" class="fi" name="company_name" maxlength="100" value="<?= h($bCompany) ?>" placeholder="Bijv. Digilance Consulting">
            <div style="margin-top:14px;"><button type="submit" class="rb primary"><?= icon('check', 14) ?> Opslaan</button></div>
          </form>
        </div>

        <div class="repo-section">
          <h3>Logo</h3>
          <p class="lead">PNG, JPG of SVG. Max 512 KB.</p>
          <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
            <div class="dropzone <?= $bLogoUrl === '' ? 'empty' : '' ?>" style="flex:0 0 260px;">
              <?php if ($bLogoUrl !== ''): ?>
                <img src="<?= h($bLogoUrl) ?>" alt="Huidig logo" style="max-width:200px;max-height:100px;object-fit:contain;">
              <?php else: ?>
                Nog geen logo
              <?php endif; ?>
            </div>
            <form method="post" enctype="multipart/form-data" style="flex:1;min-width:260px;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="branding_logo_upload">
              <label class="fl">Bestand</label>
              <input class="fi" type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml" required>
              <div style="margin-top:12px;display:flex;gap:8px;">
                <button type="submit" class="rb primary"><?= icon('upload', 12) ?> Uploaden</button>
                <?php if ($bLogoUrl !== ''): ?>
                  <button type="submit" form="logo-remove-form" class="rb danger"
                          onclick="return confirm('Logo verwijderen?');">
                    <?= icon('trash', 12) ?> Verwijderen
                  </button>
                <?php endif; ?>
              </div>
            </form>
            <?php if ($bLogoUrl !== ''): ?>
              <form id="logo-remove-form" method="post" style="display:none;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="branding_logo_remove">
              </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="repo-section">
          <h3>Favicon</h3>
          <p class="lead">ICO, PNG of SVG. Max 128 KB. Vierkant (32×32 of 64×64) werkt het best.</p>
          <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
            <div class="dropzone <?= $bFaviconUrl === '' ? 'empty' : '' ?>" style="flex:0 0 260px;">
              <?php if ($bFaviconUrl !== ''): ?>
                <img src="<?= h($bFaviconUrl) ?>" alt="Huidige favicon" style="width:48px;height:48px;object-fit:contain;">
              <?php else: ?>
                Nog geen favicon
              <?php endif; ?>
            </div>
            <form method="post" enctype="multipart/form-data" style="flex:1;min-width:260px;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="branding_favicon_upload">
              <label class="fl">Bestand</label>
              <input class="fi" type="file" name="favicon" accept=".ico,.png,.svg,image/x-icon,image/png,image/svg+xml" required>
              <div style="margin-top:12px;display:flex;gap:8px;">
                <button type="submit" class="rb primary"><?= icon('upload', 12) ?> Uploaden</button>
                <?php if ($bFaviconUrl !== ''): ?>
                  <button type="submit" form="favicon-remove-form" class="rb danger"
                          onclick="return confirm('Favicon verwijderen?');">
                    <?= icon('trash', 12) ?> Verwijderen
                  </button>
                <?php endif; ?>
              </div>
            </form>
            <?php if ($bFaviconUrl !== ''): ?>
              <form id="favicon-remove-form" method="post" style="display:none;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="branding_favicon_remove">
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>

    <?php elseif ($tab === 'users'): ?>
      <div class="repo-card-head">
        <label class="repo-search">
          <?= icon('search', 14) ?>
          <input type="search" placeholder="Zoek op naam of e-mail…" oninput="usersFilter()">
        </label>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <select class="fi sel-sm" id="users-role-filter" onchange="usersFilter()">
            <option value="">Alle rollen</option>
            <?php foreach (USER_ROLES as $r): ?>
              <option value="<?= h($r) ?>"><?= h(user_role_label($r)) ?></option>
            <?php endforeach; ?>
          </select>
          <select class="fi sel-sm" id="users-active-filter" onchange="usersFilter()">
            <option value="">Alle statussen</option>
            <option value="1">Actief</option>
            <option value="0">Inactief</option>
          </select>
          <span class="count" id="users-count"><?= count($users) ?> gebruiker<?= count($users) === 1 ? '' : 's' ?></span>
          <button type="button" class="rb primary" onclick="document.getElementById('new-user-modal').classList.add('open')">
            <?= icon('plus', 14) ?> Nieuwe gebruiker
          </button>
        </div>
      </div>
      <div class="repo-card-body">
        <table class="dt" id="users-table">
          <colgroup><col><col><col style="width:160px;"><col style="width:120px;"><col style="width:160px;"><col style="width:80px;"></colgroup>
          <thead>
            <tr><th>Naam</th><th>E-mail</th><th>Rol</th><th>Status</th><th>Laatst ingelogd</th><th></th></tr>
          </thead>
          <tbody>
            <?php if (!$users): ?>
              <tr><td colspan="6" class="empty">Geen gebruikers gevonden.</td></tr>
            <?php else: foreach ($users as $u):
              $href = APP_BASE_URL . '/pages/user_edit.php?id=' . (int)$u['id'];
              $isActive = (int)$u['active'] === 1;
            ?>
              <tr class="row-link"
                  data-href="<?= h($href) ?>"
                  data-search="<?= h(mb_strtolower($u['name'] . ' ' . $u['email'])) ?>"
                  data-role="<?= h($u['role']) ?>"
                  data-active="<?= $isActive ? '1' : '0' ?>"
                  onclick="if(event.target.closest('[data-no-rowlink]'))return;location.href=this.dataset.href;">
                <td><span class="name"><?= h($u['name']) ?></span></td>
                <td class="muted"><?= h($u['email']) ?></td>
                <td><?= user_role_badge($u['role']) ?></td>
                <td>
                  <?php if ($isActive): ?>
                    <span class="cpill green">Actief</span>
                  <?php else: ?>
                    <span class="cpill gray">Inactief</span>
                  <?php endif; ?>
                </td>
                <td class="muted"><?= $u['last_login'] ? h(date('d-m-Y H:i', strtotime($u['last_login']))) : '—' ?></td>
                <td style="text-align:right;" data-no-rowlink>
                  <a href="<?= h($href) ?>" class="rb sm ghost" title="Bewerken"><?= icon('edit', 12) ?></a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>

        <!-- ─── Autorisatiematrix (auto-gegenereerd uit includes/authz.php) ─ -->
        <div class="repo-section" style="border-top:1px solid var(--border);">
          <h3>Autorisatiematrix</h3>
          <p class="lead">
            Elke kolom is een rol, elke rij een actie. Een vinkje betekent dat die rol de actie mag uitvoeren.
            Deze matrix wordt direct uit de code gelezen, dus hij is altijd in sync met de werkelijke permissies.
          </p>
          <div style="overflow-x:auto;border:1px solid var(--border);border-radius:var(--r-sm);">
            <table class="dt">
              <thead>
                <tr>
                  <th>Actie</th>
                  <?php foreach (USER_ROLES as $r): ?>
                    <th style="text-align:center;white-space:nowrap;"><?= h(user_role_label($r)) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach (CAPABILITIES as $capKey => $capLabel): ?>
                  <tr>
                    <td><?= h($capLabel) ?></td>
                    <?php foreach (USER_ROLES as $r): ?>
                      <td style="text-align:center;">
                        <?php if (in_array($capKey, role_capabilities($r), true)): ?>
                          <span class="ck">&check;</span>
                        <?php else: ?>
                          <span class="nope">—</span>
                        <?php endif; ?>
                      </td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="lead" style="margin:10px 0 0;">
            <strong>Transparantie-principe:</strong> wie een traject mag inzien, ziet alle tabs
            (details, collega's, structuur, scoring, leveranciers, weging). Bewerk-knoppen zijn
            alleen zichtbaar voor rollen met de juiste rechten.
          </p>
        </div>
      </div>

      <!-- Nieuwe gebruiker modal -->
      <div class="rmodal-bg" id="new-user-modal" onclick="if(event.target===this)this.classList.remove('open')">
        <div class="rmodal">
          <h3>Nieuwe gebruiker</h3>
          <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label class="fl">Naam</label>
            <input class="fi" type="text" name="name" required maxlength="150" autofocus>
            <label class="fl">E-mailadres</label>
            <input class="fi" type="email" name="email" required maxlength="190">
            <label class="fl">Rol</label>
            <select class="fi" name="role" required>
              <?php foreach (USER_ROLES as $r): ?>
                <option value="<?= h($r) ?>" <?= $r === 'key_user' ? 'selected' : '' ?>><?= h(user_role_label($r)) ?></option>
              <?php endforeach; ?>
            </select>
            <label class="fl">Wachtwoord</label>
            <input class="fi" type="password" name="password" required minlength="8" placeholder="Minimaal 8 tekens">
            <div class="actions">
              <button type="button" class="rb ghost" onclick="document.getElementById('new-user-modal').classList.remove('open')">Annuleren</button>
              <button type="submit" class="rb primary">Aanmaken</button>
            </div>
          </form>
        </div>
      </div>

      <script>
      (function(){
        window.usersFilter = function(){
          const inp = document.querySelector('.repo-card-head .repo-search input');
          const q = (inp ? inp.value : '').toLowerCase().trim();
          const role = document.getElementById('users-role-filter').value;
          const act  = document.getElementById('users-active-filter').value;
          let shown = 0;
          document.querySelectorAll('#users-table tbody tr.row-link').forEach(tr => {
            const s = tr.dataset.search || '';
            const r = tr.dataset.role || '';
            const a = tr.dataset.active || '';
            const ok = (!q || s.indexOf(q) !== -1) && (!role || role === r) && (act === '' || act === a);
            tr.style.display = ok ? '' : 'none';
            if (ok) shown++;
          });
          const c = document.getElementById('users-count');
          if (c) c.textContent = shown + ' gebruiker' + (shown === 1 ? '' : 's');
        };
        document.addEventListener('keydown', function(e){
          if (e.key === 'Escape') {
            document.querySelectorAll('.rmodal-bg.open').forEach(m => m.classList.remove('open'));
          }
        });
      })();
      </script>

    <?php elseif ($tab === 'mail'): ?>
      <?php
        $mCfg      = mail_config();
        $mDriver   = $mCfg['driver'];
        $mFrom     = $mCfg['from'];
        $mFromName = $mCfg['fromName'];
        $mHost     = $mCfg['host'];
        $mPort     = $mCfg['port'] ?: 587;
        $mUser     = $mCfg['user'];
        $mSecure   = $mCfg['secure'];
        $mHasPwd   = setting_get('smtp_pwd_enc') !== '';
      ?>
      <div class="repo-card-head">
        <div style="color:var(--muted);font-size:12.5px;">Afzender en SMTP-verbinding.</div>
      </div>
      <div class="repo-card-body">
        <div class="repo-section">
          <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mail_save">
            <div class="fi-row">
              <div class="fcol">
                <label class="fl">Driver</label>
                <select class="fi" name="mail_driver" onchange="document.getElementById('smtp-fields').style.display=this.value==='smtp'?'':'none'">
                  <option value="log"  <?= $mDriver==='log'  ? 'selected' : '' ?>>Log (logs/mail.log)</option>
                  <option value="smtp" <?= $mDriver==='smtp' ? 'selected' : '' ?>>SMTP</option>
                </select>
              </div>
              <div class="fcol">
                <label class="fl">Afzender-naam</label>
                <input class="fi" type="text" name="mail_from_name" maxlength="100" value="<?= h($mFromName) ?>">
              </div>
              <div class="fcol">
                <label class="fl">Afzender-e-mail</label>
                <input class="fi" type="email" name="mail_from" maxlength="190" value="<?= h($mFrom) ?>" placeholder="noreply@voorbeeld.nl">
              </div>
            </div>

            <div id="smtp-fields" style="<?= $mDriver==='smtp' ? '' : 'display:none;' ?>;margin-top:14px;">
              <div class="fi-row">
                <div class="fcol" style="flex:2;">
                  <label class="fl">SMTP-host</label>
                  <input class="fi" type="text" name="smtp_host" maxlength="190" value="<?= h($mHost) ?>" placeholder="smtp.voorbeeld.nl">
                </div>
                <div class="fcol-sm">
                  <label class="fl">Poort</label>
                  <input class="fi" type="number" name="smtp_port" min="1" max="65535" value="<?= (int)$mPort ?>">
                </div>
                <div class="fcol-sm">
                  <label class="fl">Beveiliging</label>
                  <select class="fi" name="smtp_secure">
                    <option value="tls" <?= $mSecure==='tls' ? 'selected' : '' ?>>TLS</option>
                    <option value="ssl" <?= $mSecure==='ssl' ? 'selected' : '' ?>>SSL</option>
                    <option value=""    <?= $mSecure===''    ? 'selected' : '' ?>>Geen</option>
                  </select>
                </div>
              </div>
              <div class="fi-row" style="margin-top:14px;">
                <div class="fcol">
                  <label class="fl">SMTP-gebruiker</label>
                  <input class="fi" type="text" name="smtp_user" maxlength="190" value="<?= h($mUser) ?>" autocomplete="off">
                </div>
                <div class="fcol">
                  <label class="fl">SMTP-wachtwoord</label>
                  <input class="fi" type="password" name="smtp_pass" maxlength="190" value=""
                         placeholder="<?= $mHasPwd ? '•••••••• (laat leeg = ongewijzigd)' : 'Wachtwoord' ?>"
                         autocomplete="new-password">
                </div>
              </div>
              <?php if ($mHasPwd): ?>
                <label style="display:inline-flex;gap:6px;align-items:center;margin-top:10px;color:var(--muted);font-size:12.5px;">
                  <input type="checkbox" name="clear_pass" value="1"> Wachtwoord wissen
                </label>
              <?php endif; ?>
              <p class="lead" style="margin:10px 0 0;">
                Het wachtwoord wordt versleuteld opgeslagen (AES-256-GCM, sleutel in <code>.env</code>).
              </p>
            </div>

            <div style="margin-top:18px;display:flex;gap:8px;">
              <button type="submit" class="rb primary"><?= icon('check', 14) ?> Opslaan</button>
              <button type="submit" form="mail-test-form" class="rb ghost"><?= icon('mail', 14) ?> Testmail sturen</button>
            </div>
          </form>
          <form id="mail-test-form" method="post" style="display:none;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mail_test">
          </form>
        </div>
      </div>

    <?php elseif ($tab === 'structure'): ?>
      <?php
        $wipeOk  = structure_wipe_allowed();
        $isEmpty = structure_is_empty();
        $nReq    = (int)db()->query('SELECT COUNT(*) FROM requirements')->fetchColumn();
        $nLev    = (int)db()->query('SELECT COUNT(*) FROM leveranciers')->fetchColumn();
      ?>
      <div class="repo-card-head">
        <div style="color:var(--muted);font-size:12.5px;">Categorieën, subcategorieën, applicatiesoorten en DEMO-vragen.</div>
      </div>
      <div class="repo-card-body">
        <div class="repo-section">
          <h3>Downloaden</h3>
          <p class="lead">Download de huidige structuur of een leeg template als Excel.</p>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a class="rb ghost" href="<?= h(APP_BASE_URL) ?>/pages/instellingen.php?action=structure_download&amp;mode=current">
              <?= icon('download', 14) ?> Huidige structuur (.xlsx)
            </a>
            <a class="rb ghost" href="<?= h(APP_BASE_URL) ?>/pages/instellingen.php?action=structure_download&amp;mode=template">
              <?= icon('download', 14) ?> Leeg template (.xlsx)
            </a>
          </div>
        </div>

        <div class="repo-section">
          <h3>Uploaden</h3>
          <?php if (!$isEmpty): ?>
            <p class="lead">
              Upload is alleen mogelijk op een <strong>lege</strong> structuur — wis eerst de huidige structuur of begin met een schone database.
            </p>
          <?php else: ?>
            <p class="lead">Upload een ingevulde template (.xlsx). De import is strict en wordt bij een fout volledig teruggedraaid.</p>
            <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="structure_upload">
              <input class="fi" type="file" name="file" accept=".xlsx" required style="flex:1;min-width:240px;">
              <button type="submit" class="rb primary"><?= icon('upload', 14) ?> Importeren</button>
            </form>
          <?php endif; ?>
        </div>

        <div class="repo-section">
          <h3>Wissen</h3>
          <?php if (!$wipeOk): ?>
            <p class="lead">
              Wipe geblokkeerd — er zijn nog
              <strong><?= $nReq ?></strong> requirements en
              <strong><?= $nLev ?></strong> leveranciers in de app.
            </p>
            <button class="rb dangerf" disabled>
              <?= icon('trash', 14) ?> Structuur wissen
            </button>
          <?php else: ?>
            <p class="lead">
              Geen requirements, geen leveranciers — wipe is mogelijk. Typ
              <code>WIPE</code> ter bevestiging.
            </p>
            <form method="post" onsubmit="return confirm('Structuur definitief wissen? Dit kan niet worden teruggedraaid.');"
                  style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="structure_wipe">
              <input class="fi" type="text" name="confirm" placeholder="WIPE" style="width:140px;" autocomplete="off">
              <button type="submit" class="rb dangerf"><?= icon('trash', 14) ?> Structuur wissen</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

    <?php elseif ($tab === 'app'): ?>
      <div class="repo-card-head">
        <div style="color:var(--muted);font-size:12.5px;">Systeem- en versie-informatie.</div>
      </div>
      <div class="repo-card-body">
        <table class="dt">
          <colgroup><col style="width:200px;"><col></colgroup>
          <tbody>
            <tr><td class="muted">Versie</td><td><code><?= h(APP_VERSION) ?></code></td></tr>
            <tr><td class="muted">Naam</td><td><?= h(setting_app_name()) ?></td></tr>
            <tr><td class="muted">Base URL</td><td><code><?= h(APP_BASE_URL) ?></code></td></tr>
            <tr><td class="muted">PHP</td><td><code><?= h(PHP_VERSION) ?></code></td></tr>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div><!-- /.repo-card -->
</div><!-- /.repo-screen -->
<?php };

require __DIR__ . '/../templates/layout.php';
