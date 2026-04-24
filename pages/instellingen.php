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

$q      = input_str('q');
$role   = input_str('role');
$active = input('active', '');
$activeFilter = ($active === '1' || $active === '0') ? (int)$active : null;

$users = users_list($q, $role, $activeFilter);

$pageTitle  = 'Instellingen';
$currentNav = 'instellingen';

$bodyRenderer = function () use ($users, $q, $role, $activeFilter) { ?>
  <div class="page-header">
    <div>
      <h1>Instellingen</h1>
      <p>Gebruikersbeheer en systeemconfiguratie.</p>
    </div>
  </div>

  <!-- ─── Branding ─────────────────────────────────────────────────── -->
  <?php
    $bAppName = setting_get('app_name');
    $bCompany = setting_get('company_name');
    $bLogoUrl    = setting_logo_url();
    $bFaviconUrl = setting_favicon_url();
  ?>
  <div class="card" style="margin-bottom:24px;">
    <div class="card-title">
      <h2>Branding</h2>
      <span class="muted small">App-naam, bedrijfsnaam en logo</span>
    </div>
    <div class="row" style="gap:24px;align-items:flex-start;flex-wrap:wrap;">
      <form method="post" style="flex:1;min-width:280px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="branding_save">
        <label class="field">App-naam
          <input type="text" name="app_name" maxlength="100" required value="<?= h($bAppName) ?>">
        </label>
        <label class="field">Bedrijfsnaam <span class="muted small">(optioneel)</span>
          <input type="text" name="company_name" maxlength="100" value="<?= h($bCompany) ?>"
                 placeholder="Bijv. Digilance Consulting">
        </label>
        <p class="muted small" style="margin:4px 0 10px;">
          De app-naam verschijnt in de titel, header en login-scherm.
          De bedrijfsnaam wordt in de zijbalk getoond als aanvulling.
        </p>
        <button type="submit" class="btn"><?= icon('check', 14) ?> Opslaan</button>
      </form>

      <div style="flex:0 0 260px;">
        <div class="muted small" style="margin-bottom:6px;font-weight:600;">Logo</div>
        <div style="border:1px dashed var(--border,#e5e7eb);border-radius:10px;padding:18px;display:flex;align-items:center;justify-content:center;min-height:120px;background:#f9fafb;">
          <?php if ($bLogoUrl !== ''): ?>
            <img src="<?= h($bLogoUrl) ?>" alt="Huidig logo" style="max-width:200px;max-height:100px;object-fit:contain;">
          <?php else: ?>
            <span class="muted small">Nog geen logo</span>
          <?php endif; ?>
        </div>
        <form method="post" enctype="multipart/form-data" style="margin-top:10px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="branding_logo_upload">
          <label class="field" style="margin-bottom:6px;">
            <input class="input" type="file" name="logo"
                   accept="image/png,image/jpeg,image/svg+xml" required>
          </label>
          <div class="row-sm" style="gap:6px;">
            <button type="submit" class="btn sm"><?= icon('upload', 12) ?> Uploaden</button>
            <?php if ($bLogoUrl !== ''): ?>
              <button type="submit" form="logo-remove-form" class="btn sm ghost"
                      onclick="return confirm('Logo verwijderen?');">
                <?= icon('trash', 12) ?> Verwijderen
              </button>
            <?php endif; ?>
          </div>
          <p class="muted small" style="margin:6px 0 0;">PNG, JPG of SVG. Max 512 KB.</p>
        </form>
        <?php if ($bLogoUrl !== ''): ?>
          <form id="logo-remove-form" method="post" style="display:none;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="branding_logo_remove">
          </form>
        <?php endif; ?>
      </div>

      <div style="flex:0 0 220px;">
        <div class="muted small" style="margin-bottom:6px;font-weight:600;">Favicon</div>
        <div style="border:1px dashed var(--border,#e5e7eb);border-radius:10px;padding:18px;display:flex;align-items:center;justify-content:center;min-height:120px;background:#f9fafb;">
          <?php if ($bFaviconUrl !== ''): ?>
            <img src="<?= h($bFaviconUrl) ?>" alt="Huidige favicon" style="width:48px;height:48px;object-fit:contain;">
          <?php else: ?>
            <span class="muted small">Nog geen favicon</span>
          <?php endif; ?>
        </div>
        <form method="post" enctype="multipart/form-data" style="margin-top:10px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="branding_favicon_upload">
          <label class="field" style="margin-bottom:6px;">
            <input class="input" type="file" name="favicon"
                   accept=".ico,.png,.svg,image/x-icon,image/png,image/svg+xml" required>
          </label>
          <div class="row-sm" style="gap:6px;">
            <button type="submit" class="btn sm"><?= icon('upload', 12) ?> Uploaden</button>
            <?php if ($bFaviconUrl !== ''): ?>
              <button type="submit" form="favicon-remove-form" class="btn sm ghost"
                      onclick="return confirm('Favicon verwijderen?');">
                <?= icon('trash', 12) ?> Verwijderen
              </button>
            <?php endif; ?>
          </div>
          <p class="muted small" style="margin:6px 0 0;">ICO, PNG of SVG. Max 128 KB. Vierkant (32×32 of 64×64) werkt het best.</p>
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

  <!-- ─── Gebruikers ───────────────────────────────────────────────── -->
  <div class="card">
    <div class="card-title" style="display:flex;align-items:center;gap:10px;">
      <h2 style="margin:0;">Gebruikers</h2>
      <span class="badge indigo"><?= count($users) ?></span>
      <button type="button" class="btn" style="margin-left:auto;"
              onclick="document.getElementById('new-user-modal').style.display='flex'">
        <?= icon('plus', 14) ?> Nieuwe gebruiker
      </button>
    </div>

    <form method="get" class="row" style="flex-wrap:wrap;gap:8px;align-items:flex-end;margin-top:6px;">
      <div class="search" style="flex:1;min-width:200px;">
        <?= icon('search', 14) ?>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Zoek op naam of e-mail…">
      </div>
      <select name="role" class="input" style="width:auto;margin-top:0;" onchange="this.form.submit()">
        <option value="">Alle rollen</option>
        <?php foreach (USER_ROLES as $r): ?>
          <option value="<?= h($r) ?>" <?= $role === $r ? 'selected' : '' ?>><?= h(user_role_label($r)) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="active" class="input" style="width:auto;margin-top:0;" onchange="this.form.submit()">
        <option value="">Alle statussen</option>
        <option value="1" <?= $activeFilter === 1 ? 'selected' : '' ?>>Actief</option>
        <option value="0" <?= $activeFilter === 0 ? 'selected' : '' ?>>Inactief</option>
      </select>
      <button type="submit" class="btn ghost">Filteren</button>
      <?php if ($q !== '' || $role !== '' || $activeFilter !== null): ?>
        <a href="<?= h(APP_BASE_URL) ?>/pages/instellingen.php" class="btn ghost">Reset</a>
      <?php endif; ?>
    </form>

    <div class="table-wrap" style="margin-top:10px;">
      <table class="table">
        <thead><tr>
          <th>Naam</th><th>E-mail</th><th>Rol</th><th>Status</th>
          <th>Laatst ingelogd</th><th class="right">&nbsp;</th>
        </tr></thead>
        <tbody>
          <?php if (!$users): ?>
            <tr><td colspan="6" class="muted center" style="padding:24px;">Geen gebruikers gevonden.</td></tr>
          <?php endif; ?>
          <?php foreach ($users as $u):
              $href = APP_BASE_URL . '/pages/user_edit.php?id=' . (int)$u['id']; ?>
            <tr class="row-link" data-href="<?= h($href) ?>">
              <td><strong><?= h($u['name']) ?></strong></td>
              <td class="muted small"><?= h($u['email']) ?></td>
              <td><?= user_role_badge($u['role']) ?></td>
              <td>
                <?php if ((int)$u['active'] === 1): ?>
                  <span class="badge green">Actief</span>
                <?php else: ?>
                  <span class="badge gray">Inactief</span>
                <?php endif; ?>
              </td>
              <td class="muted small">
                <?= $u['last_login'] ? h(date('d-m-Y H:i', strtotime($u['last_login']))) : '—' ?>
              </td>
              <td class="right" data-no-rowlink>
                <a href="<?= h($href) ?>" class="btn sm ghost"><?= icon('edit', 12) ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ─── Autorisatiematrix (auto-gegenereerd uit includes/authz.php) ─ -->
  <div class="card" style="margin-top:24px;">
    <div class="card-title"><h2>Autorisatiematrix</h2></div>
    <p class="muted small" style="margin-top:0;">
      Elke kolom is een rol, elke rij een actie. Een vinkje betekent dat die rol
      de actie mag uitvoeren. Deze matrix wordt direct uit de code gelezen, dus
      hij is altijd in sync met de werkelijke permissies.
    </p>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="text-align:left;">Actie</th>
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
                    <span style="color:var(--green-600);font-weight:700;">&check;</span>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="muted small" style="margin:8px 0 0;">
      <strong>Transparantie-principe:</strong> wie een traject mag inzien, ziet alle tabs
      (details, collega's, structuur, scoring, leveranciers, weging). Bewerk-knoppen zijn
      alleen zichtbaar voor rollen met de juiste rechten.
    </p>
  </div>

  <!-- ─── Mail-configuratie ──────────────────────────────────────── -->
  <?php
    $mCfg       = mail_config();
    $mDriver    = $mCfg['driver'];
    $mFrom      = $mCfg['from'];
    $mFromName  = $mCfg['fromName'];
    $mHost      = $mCfg['host'];
    $mPort      = $mCfg['port'] ?: 587;
    $mUser      = $mCfg['user'];
    $mSecure    = $mCfg['secure'];
    $mHasPwd    = setting_get('smtp_pwd_enc') !== '';
  ?>
  <div class="card" style="margin-top:24px;">
    <div class="card-title">
      <h2>Mail-configuratie</h2>
      <span class="muted small">Afzender en SMTP-verbinding</span>
    </div>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="mail_save">
      <div class="row" style="gap:16px;flex-wrap:wrap;">
        <label class="field" style="flex:1;min-width:220px;">Driver
          <select name="mail_driver" class="input" onchange="document.getElementById('smtp-fields').style.display=this.value==='smtp'?'':'none'">
            <option value="log"  <?= $mDriver==='log'  ? 'selected' : '' ?>>Log (logs/mail.log)</option>
            <option value="smtp" <?= $mDriver==='smtp' ? 'selected' : '' ?>>SMTP</option>
          </select>
        </label>
        <label class="field" style="flex:1;min-width:220px;">Afzender-naam
          <input type="text" name="mail_from_name" maxlength="100" value="<?= h($mFromName) ?>">
        </label>
        <label class="field" style="flex:1;min-width:220px;">Afzender-e-mail
          <input type="email" name="mail_from" maxlength="190" value="<?= h($mFrom) ?>" placeholder="noreply@voorbeeld.nl">
        </label>
      </div>

      <div id="smtp-fields" style="<?= $mDriver==='smtp' ? '' : 'display:none;' ?>">
        <div class="row" style="gap:16px;flex-wrap:wrap;margin-top:8px;">
          <label class="field" style="flex:2;min-width:240px;">SMTP-host
            <input type="text" name="smtp_host" maxlength="190" value="<?= h($mHost) ?>" placeholder="smtp.voorbeeld.nl">
          </label>
          <label class="field" style="flex:0 0 120px;">Poort
            <input type="number" name="smtp_port" min="1" max="65535" value="<?= (int)$mPort ?>">
          </label>
          <label class="field" style="flex:0 0 140px;">Beveiliging
            <select name="smtp_secure" class="input">
              <option value="tls" <?= $mSecure==='tls' ? 'selected' : '' ?>>TLS</option>
              <option value="ssl" <?= $mSecure==='ssl' ? 'selected' : '' ?>>SSL</option>
              <option value=""    <?= $mSecure===''    ? 'selected' : '' ?>>Geen</option>
            </select>
          </label>
        </div>
        <div class="row" style="gap:16px;flex-wrap:wrap;">
          <label class="field" style="flex:1;min-width:240px;">SMTP-gebruiker
            <input type="text" name="smtp_user" maxlength="190" value="<?= h($mUser) ?>" autocomplete="off">
          </label>
          <label class="field" style="flex:1;min-width:240px;">SMTP-wachtwoord
            <input type="password" name="smtp_pass" maxlength="190" value=""
                   placeholder="<?= $mHasPwd ? '•••••••• (laat leeg = ongewijzigd)' : 'Wachtwoord' ?>"
                   autocomplete="new-password">
          </label>
        </div>
        <?php if ($mHasPwd): ?>
          <label class="muted small" style="display:inline-flex;gap:6px;align-items:center;margin-top:4px;">
            <input type="checkbox" name="clear_pass" value="1"> Wachtwoord wissen
          </label>
        <?php endif; ?>
        <p class="muted small" style="margin:6px 0 10px;">
          Het wachtwoord wordt versleuteld opgeslagen (AES-256-GCM, sleutel in <code>.env</code>).
        </p>
      </div>

      <div class="row-sm" style="gap:8px;margin-top:10px;">
        <button type="submit" class="btn"><?= icon('check', 14) ?> Opslaan</button>
        <button type="submit" form="mail-test-form" class="btn ghost"><?= icon('mail', 14) ?> Testmail sturen</button>
      </div>
    </form>
    <form id="mail-test-form" method="post" style="display:none;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="mail_test">
    </form>
  </div>

  <!-- ─── Structuur ──────────────────────────────────────────────── -->
  <?php
    $wipeOk = structure_wipe_allowed();
    $nReq = (int)db()->query('SELECT COUNT(*) FROM requirements')->fetchColumn();
    $nLev = (int)db()->query('SELECT COUNT(*) FROM leveranciers')->fetchColumn();
  ?>
  <div class="card" style="margin-top:24px;">
    <div class="card-title">
      <h2>Structuur</h2>
      <span class="muted small">Categorieën, subcategorieën, applicatiesoorten en DEMO-vragen</span>
    </div>
    <p class="muted small" style="margin-top:0;">
      Download de huidige structuur of een leeg template als Excel, of upload
      een ingevulde template in een lege database. Een wipe verwijdert de
      volledige structuur en is alleen mogelijk zolang er géén requirements en
      géén leveranciers bestaan.
    </p>
    <div class="row-sm" style="gap:8px;flex-wrap:wrap;">
      <a class="btn ghost" href="<?= h(APP_BASE_URL) ?>/pages/instellingen.php?action=structure_download&amp;mode=current">
        <?= icon('download', 14) ?> Huidige structuur (.xlsx)
      </a>
      <a class="btn ghost" href="<?= h(APP_BASE_URL) ?>/pages/instellingen.php?action=structure_download&amp;mode=template">
        <?= icon('download', 14) ?> Leeg template (.xlsx)
      </a>
    </div>

    <?php $isEmpty = structure_is_empty(); ?>
    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border,#e5e7eb);">
      <div style="font-weight:600;margin-bottom:4px;">Structuur uploaden</div>
      <?php if (!$isEmpty): ?>
        <p class="muted small" style="margin:0;">
          Upload is alleen mogelijk op een <strong>lege</strong> structuur — wis
          eerst de huidige structuur of begin met een schone database.
        </p>
      <?php else: ?>
        <p class="muted small" style="margin:0 0 6px;">
          Upload een ingevulde template (.xlsx). De import is strict en wordt bij
          een fout volledig teruggedraaid.
        </p>
        <form method="post" enctype="multipart/form-data" class="row-sm" style="gap:8px;align-items:center;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="structure_upload">
          <input class="input" type="file" name="file" accept=".xlsx" required style="flex:1;min-width:240px;">
          <button type="submit" class="btn"><?= icon('upload', 14) ?> Importeren</button>
        </form>
      <?php endif; ?>
    </div>

    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border,#e5e7eb);">
      <div style="font-weight:600;margin-bottom:4px;">Structuur wissen</div>
      <?php if (!$wipeOk): ?>
        <p class="muted small" style="margin:0 0 6px;">
          Wipe geblokkeerd — er zijn nog
          <strong><?= $nReq ?></strong> requirements en
          <strong><?= $nLev ?></strong> leveranciers in de app.
        </p>
        <button class="btn" disabled style="opacity:.5;cursor:not-allowed;">
          <?= icon('trash', 14) ?> Structuur wissen
        </button>
      <?php else: ?>
        <p class="muted small" style="margin:0 0 6px;">
          Geen requirements, geen leveranciers — wipe is mogelijk. Typ
          <code>WIPE</code> ter bevestiging.
        </p>
        <form method="post" onsubmit="return confirm('Structuur definitief wissen? Dit kan niet worden teruggedraaid.');" class="row-sm" style="gap:8px;align-items:center;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="structure_wipe">
          <input type="text" name="confirm" class="input" placeholder="WIPE" style="width:120px;" autocomplete="off">
          <button type="submit" class="btn" style="background:var(--red-600,#dc2626);color:#fff;">
            <?= icon('trash', 14) ?> Structuur wissen
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- ─── Applicatie-info ─────────────────────────────────────────── -->
  <div class="card" style="margin-top:24px;">
    <div class="card-title"><h2>Applicatie</h2></div>
    <div class="table-wrap">
      <table class="table">
        <tbody>
          <tr><td style="width:180px;">Versie</td><td><code><?= h(APP_VERSION) ?></code></td></tr>
          <tr><td>Naam</td><td><?= h(setting_app_name()) ?></td></tr>
          <tr><td>Base URL</td><td><code><?= h(APP_BASE_URL) ?></code></td></tr>
          <tr><td>PHP</td><td><code><?= h(PHP_VERSION) ?></code></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ─── Nieuwe gebruiker modal ──────────────────────────────────── -->
  <div id="new-user-modal" class="modal-backdrop" style="display:none;"
       onclick="if(event.target===this)this.style.display='none'">
    <div class="modal">
      <div class="modal-header">
        <h2>Nieuwe gebruiker</h2>
        <button type="button" class="btn-icon"
                onclick="document.getElementById('new-user-modal').style.display='none'">
          <?= icon('x', 16) ?>
        </button>
      </div>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-body">
          <label class="field">Naam
            <input type="text" name="name" required maxlength="150" autofocus>
          </label>
          <label class="field">E-mailadres
            <input type="email" name="email" required maxlength="190">
          </label>
          <label class="field">Rol
            <select name="role" class="input" required>
              <?php foreach (USER_ROLES as $r): ?>
                <option value="<?= h($r) ?>" <?= $r === 'key_user' ? 'selected' : '' ?>><?= h(user_role_label($r)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field">Wachtwoord
            <input type="password" name="password" required minlength="8"
                   placeholder="Minimaal 8 tekens">
          </label>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn ghost"
                  onclick="document.getElementById('new-user-modal').style.display='none'">Annuleren</button>
          <button type="submit" class="btn">Aanmaken</button>
        </div>
      </form>
    </div>
  </div>
<?php };

require __DIR__ . '/../templates/layout.php';
