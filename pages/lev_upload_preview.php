<?php
/**
 * Upload → preview → commit flow voor leverancier-antwoorden.
 *
 * Stappen:
 *   1. POST met file (action=upload)   → import naar staging, redirect naar preview
 *   2. GET ?lev=X                       → toon preview met dry-run counts + per-rij classificatie
 *   3. POST action=commit               → schrijf auto-scores, registreer upload-metadata
 *   4. POST action=cancel               → gooi staging weg
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/leveranciers.php';
require_once __DIR__ . '/../includes/leverancier_excel.php';
require_once __DIR__ . '/../includes/lev_answers.php';
require_once __DIR__ . '/../includes/scoring.php';
require_login();

require_can('leveranciers.edit');

$leverancierId = input_int('lev');
if (!$leverancierId) redirect('pages/trajecten.php');

$lev = db_one('SELECT * FROM leveranciers WHERE id = :id', [':id' => $leverancierId]);
if (!$lev) { flash_set('error', 'Leverancier niet gevonden.'); redirect('pages/trajecten.php'); }
$trajectId = (int)$lev['traject_id'];
if (!can_edit_traject('leveranciers.edit', $trajectId)) {
    http_response_code(403); exit('Onvoldoende rechten voor dit traject.');
}

$backUrl = APP_BASE_URL . '/pages/traject_detail.php?id=' . $trajectId . '&tab=leveranciers';

// ─── POST ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = input_str('action');

    try {
        if ($action === 'upload') {
            if (lev_scoring_started($leverancierId)) {
                throw new RuntimeException('Scoring is reeds gestart — upload niet meer mogelijk.');
            }
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Bestand ontbreekt of kon niet worden geüpload.');
            }
            $origName = (string)$_FILES['file']['name'];
            if (!preg_match('/\.xlsx$/i', $origName)) {
                throw new RuntimeException('Alleen .xlsx wordt ondersteund.');
            }

            // Oude staging/commits opruimen (overschrijf-gedrag)
            $existing = lev_upload_get($leverancierId);
            if ($existing) {
                lev_upload_delete($leverancierId);
            } else {
                // Staging zonder commit: verwijder losse answers/auto-scores
                db_exec("DELETE FROM scores WHERE leverancier_id = :l AND source = 'auto'",
                    [':l' => $leverancierId]);
                db_exec('DELETE FROM leverancier_answers WHERE leverancier_id = :l',
                    [':l' => $leverancierId]);
            }

            // Nieuw bestand veilig opslaan
            $dir = APP_ROOT . '/uploads/leverancier_excel';
            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                throw new RuntimeException('Upload-directory ontbreekt. Run /pages/migrate.php.');
            }
            $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $origName);
            $stored   = $dir . '/' . $leverancierId . '_' . date('Ymd_His') . '_' . $safeName;
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $stored)) {
                throw new RuntimeException('Kon geüpload bestand niet opslaan.');
            }

            $res = leverancier_excel_import($leverancierId, $stored);
            if (!$res['ok']) {
                @unlink($stored);
                $msg = 'Import mislukt: ' . implode(' | ', $res['errors']);
                throw new RuntimeException($msg);
            }

            // Staging-pad onthouden in session zodat commit het kan registreren
            $_SESSION['lev_upload_staging'][$leverancierId] = [
                'stored_path'   => $stored,
                'original_name' => $origName,
            ];

            flash_set('success', sprintf(
                'Bestand ingelezen: %d rij(en). Bekijk het voorstel en bevestig.',
                (int)$res['rows']
            ));
            redirect('pages/lev_upload_preview.php?lev=' . $leverancierId);
        }

        if ($action === 'commit') {
            $stage = $_SESSION['lev_upload_staging'][$leverancierId] ?? null;
            if (!$stage) throw new RuntimeException('Geen openstaande upload — upload opnieuw.');
            if (lev_scoring_started($leverancierId)) {
                throw new RuntimeException('Scoring is reeds gestart.');
            }
            $out = lev_auto_score_commit($leverancierId, $trajectId);
            lev_upload_record($leverancierId, $trajectId,
                (string)$stage['original_name'], (string)$stage['stored_path'],
                $out['counts']);
            unset($_SESSION['lev_upload_staging'][$leverancierId]);

            $msg = sprintf('Toegepast: %d automatisch gescoord, %d handmatig te scoren, %d KO-gefaald, %d N.v.t.',
                $out['counts']['auto_max'] + $out['counts']['auto_min'] + $out['counts']['ko_fail_auto'],
                $out['counts']['manual']   + $out['counts']['ko_manual'],
                $out['counts']['ko_fail_auto'],
                $out['counts']['skip']);
            flash_set('success', $msg);
            if ($out['ko_fails']) {
                flash_set('warning', 'Leverancier afgewezen op knock-out: ' . implode(' | ', $out['ko_fails']));
            }
            redirect('pages/traject_detail.php?id=' . $trajectId . '&tab=leveranciers');
        }

        if ($action === 'cancel') {
            $stage = $_SESSION['lev_upload_staging'][$leverancierId] ?? null;
            db_exec("DELETE FROM scores WHERE leverancier_id = :l AND source = 'auto'",
                [':l' => $leverancierId]);
            db_exec('DELETE FROM leverancier_answers WHERE leverancier_id = :l',
                [':l' => $leverancierId]);
            if ($stage && !empty($stage['stored_path']) && file_exists($stage['stored_path'])) {
                @unlink($stage['stored_path']);
            }
            unset($_SESSION['lev_upload_staging'][$leverancierId]);
            flash_set('info', 'Upload geannuleerd.');
            redirect('pages/traject_detail.php?id=' . $trajectId . '&tab=leveranciers');
        }

        if ($action === 'delete') {
            lev_upload_delete($leverancierId);
            flash_set('success', 'Upload en auto-scores verwijderd.');
            redirect('pages/traject_detail.php?id=' . $trajectId . '&tab=leveranciers');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect('pages/traject_detail.php?id=' . $trajectId . '&tab=leveranciers');
    }
}

// ─── GET: preview ────────────────────────────────────────────────────────────
$dry = lev_auto_score_dry_run($leverancierId, $trajectId);
$hasStaging = isset($_SESSION['lev_upload_staging'][$leverancierId]);
$committed  = lev_upload_get($leverancierId);

$pageTitle  = 'Upload-voorstel — ' . $lev['name'];
$currentNav = 'trajecten';

$classColors = [
    'auto_max'     => '#10b981',
    'auto_min'     => '#6b7280',
    'manual'       => '#3b82f6',
    'skip'         => '#9ca3af',
    'ko_fail_auto' => '#ef4444',
    'ko_manual'    => '#f59e0b',
];
$classLabels = [
    'auto_max'     => 'Auto max',
    'auto_min'     => 'Auto min',
    'manual'       => 'Handmatig',
    'skip'         => 'N.v.t.',
    'ko_fail_auto' => 'KO gefaald',
    'ko_manual'    => 'KO handmatig',
];

$bodyRenderer = function () use (
    $lev, $trajectId, $dry, $hasStaging, $committed, $backUrl,
    $classColors, $classLabels
) {
    $c = $dry['counts'];
?>
  <div class="page-header">
    <div>
      <div class="row-sm" style="align-items:center;margin-bottom:4px;">
        <a href="<?= h($backUrl) ?>" class="muted small">← Terug naar leveranciers</a>
      </div>
      <div class="ph-title">Upload-voorstel: <?= h($lev['name']) ?></div>
      <div class="ph-sub">
        <?php if ($committed): ?>
          Actuele upload (<?= h($committed['original_name']) ?>, <?= h(date('d-m-Y H:i', strtotime($committed['uploaded_at']))) ?>)
        <?php elseif ($hasStaging): ?>
          Voorstel op basis van nieuwe upload — nog niet toegepast
        <?php else: ?>
          Geen actieve upload
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($c['total'] === 0): ?>
    <div class="sc"><div class="sc-body">
      <p>Er zijn geen antwoorden gevonden. Upload eerst een ingevuld Excel-bestand.</p>
    </div></div>
  <?php else: ?>

    <!-- Summary -->
    <div class="home-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px;">
      <?php foreach ([
        ['auto_max',     'Auto max (Ja z. toelichting)'],
        ['auto_min',     'Auto min (Nee z. toelichting)'],
        ['manual',       'Handmatig te scoren'],
        ['ko_manual',    'Knock-out (handmatig)'],
        ['ko_fail_auto', 'Knock-out gefaald'],
        ['skip',         'N.v.t.'],
      ] as [$k, $label]):
        $n   = (int)$c[$k];
        $clr = $classColors[$k];
      ?>
        <div class="sc" style="text-align:center;padding:14px;">
          <div style="font-size:28px;font-weight:800;color:<?= h($clr) ?>;"><?= $n ?></div>
          <div class="muted small"><?= h($label) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Actions -->
    <?php if ($hasStaging): ?>
      <div class="sc" style="margin-bottom:16px;">
        <div class="sc-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
          <div>
            <strong>Bevestig om de auto-scores toe te passen.</strong>
            <div class="muted small">Je kunt de upload later nog verwijderen of overschrijven, zolang scoring niet is begonnen.</div>
          </div>
          <div style="display:flex;gap:8px;">
            <form method="post" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="cancel">
              <button class="btn ghost" type="submit">Annuleren</button>
            </form>
            <form method="post" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="commit">
              <button class="btn" type="submit">Toepassen &amp; scoren starten</button>
            </form>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Per-rij table -->
    <div class="sc">
      <div class="sc-head"><div class="sc-title">Classificatie per requirement</div></div>
      <div class="sc-body">
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th style="width:14%;">Categorie</th>
                <th style="width:10%;">Code</th>
                <th>Titel</th>
                <th style="width:8%;">Antwoord</th>
                <th style="width:14%;">Classificatie</th>
                <th>Reden</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dry['rows'] as $r):
                $cls = (string)$r['class'];
                $clr = $classColors[$cls] ?? '#9ca3af';
              ?>
                <tr>
                  <td class="small"><?= h($r['scope']) ?></td>
                  <td class="small"><code><?= h($r['code']) ?></code><?php if ($r['type'] === 'ko'): ?> <span class="badge red">KO</span><?php endif; ?></td>
                  <td class="small"><?= h($r['title']) ?></td>
                  <td class="small"><?= h(lev_answer_label($r['answer_choice'] ?? '')) ?></td>
                  <td><span class="badge" style="background:<?= h($clr) ?>;color:#fff;"><?= h($classLabels[$cls] ?? $cls) ?></span></td>
                  <td class="small muted"><?= h($r['reason']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>
<?php };

require __DIR__ . '/../templates/layout.php';
