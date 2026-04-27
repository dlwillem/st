<?php
/**
 * Gebruiker bewerken — rol, status, naam, e-mail, wachtwoord resetten, verwijderen.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/users.php';
require_can('users.edit');

$id = input_int('id');
if (!$id) redirect('pages/instellingen.php');

$user = user_find($id);
if (!$user) {
    flash_set('error', 'Gebruiker niet gevonden.');
    redirect('pages/instellingen.php');
}

$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = input_str('action');
    if (is_demo_mode()) {
        flash_set('error', 'Gebruikersbeheer is uitgeschakeld in de demo-omgeving.');
        redirect('pages/instellingen.php');
    }
    try {
        if ($action === 'save') {
            $active = input('active', '0') === '1';
            // Eigen account niet deactiveren
            if ((int)$user['id'] === (int)$me['id'] && !$active) {
                throw new RuntimeException('Je kunt je eigen account niet deactiveren.');
            }
            user_update(
                $id,
                input_str('name'),
                input_str('email'),
                input_str('role'),
                $active
            );
            flash_set('success', 'Wijzigingen opgeslagen.');
            redirect('pages/user_edit.php?id=' . $id);

        } elseif ($action === 'password') {
            user_set_password($id, (string)($_POST['password'] ?? ''));
            flash_set('success', 'Wachtwoord bijgewerkt.');
            redirect('pages/user_edit.php?id=' . $id);

        } elseif ($action === 'delete') {
            if ((int)$user['id'] === (int)$me['id']) {
                throw new RuntimeException('Je kunt je eigen account niet verwijderen.');
            }
            user_delete($id);
            flash_set('success', 'Gebruiker verwijderd.');
            redirect('pages/instellingen.php');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect('pages/user_edit.php?id=' . $id);
    }
}

$pageTitle  = 'Gebruiker — ' . $user['name'];
$currentNav = 'instellingen';
$isSelf     = (int)$user['id'] === (int)$me['id'];

$bodyRenderer = function () use ($user, $isSelf) { ?>
  <div class="page-header">
    <div>
      <div class="row-sm" style="align-items:center;margin-bottom:4px;">
        <a href="<?= h(APP_BASE_URL) ?>/pages/instellingen.php" class="muted small">← Instellingen</a>
      </div>
      <h1><?= h($user['name']) ?></h1>
      <p class="muted small">
        <?= h($user['email']) ?> · aangemaakt <?= h(date('d-m-Y', strtotime($user['created_at']))) ?>
        <?php if ($user['last_login']): ?>
          · laatst ingelogd <?= h(date('d-m-Y H:i', strtotime($user['last_login']))) ?>
        <?php endif; ?>
      </p>
    </div>
  </div>

  <div class="card">
    <div class="card-title"><h2>Profiel en rechten</h2></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <div class="field-row">
        <label class="field">Naam
          <input type="text" name="name" required maxlength="150" value="<?= h($user['name']) ?>">
        </label>
        <label class="field">E-mailadres
          <input type="email" name="email" required maxlength="190" value="<?= h($user['email']) ?>">
        </label>
      </div>
      <div class="field-row">
        <label class="field">Rol
          <select name="role" class="input" required>
            <?php foreach (USER_ROLES as $r): ?>
              <option value="<?= h($r) ?>" <?= $user['role'] === $r ? 'selected' : '' ?>><?= h(user_role_label($r)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">Status
          <select name="active" class="input">
            <option value="1" <?= (int)$user['active'] === 1 ? 'selected' : '' ?>>Actief</option>
            <option value="0" <?= (int)$user['active'] === 0 ? 'selected' : '' ?> <?= $isSelf ? 'disabled' : '' ?>>Inactief</option>
          </select>
        </label>
      </div>
      <?php if ($isSelf): ?>
        <p class="muted small">Je bewerkt je eigen account — je kunt jezelf niet deactiveren of verwijderen.</p>
      <?php endif; ?>
      <div class="row-sm" style="margin-top:10px;">
        <button type="submit" class="btn"><?= icon('check', 14) ?> Opslaan</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-title"><h2>Wachtwoord resetten</h2></div>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <div class="field-row">
        <label class="field">Nieuw wachtwoord
          <input type="password" name="password" required minlength="8"
                 placeholder="Minimaal 8 tekens">
        </label>
      </div>
      <div class="row-sm">
        <button type="submit" class="btn ghost"
                onclick="return confirm('Wachtwoord direct vervangen?');">
          <?= icon('edit', 14) ?> Wachtwoord instellen
        </button>
      </div>
    </form>
  </div>

  <?php if (!$isSelf): ?>
    <div class="card" style="border-color:var(--red-200);">
      <div class="card-title"><h2 style="color:var(--red-700);">Gevarenzone</h2></div>
      <p class="muted small">Gebruiker verwijderen is permanent. Audit-regels blijven behouden (user_id wordt NULL).</p>
      <form method="post" onsubmit="return confirm('Deze gebruiker echt verwijderen?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn ghost" style="color:var(--red-700);">
          <?= icon('trash', 14) ?> Verwijderen
        </button>
      </form>
    </div>
  <?php endif; ?>
<?php };

require __DIR__ . '/../templates/layout.php';
