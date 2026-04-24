<?php
/**
 * Requirements-tools: Excel-import/export, duplicaat-scan.
 * ?action=export     download xlsx
 * ?action=template   download template xlsx
 * GET (zonder action): upload-formulier + duplicaat-sectie
 * POST action=upload : verwerk upload
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/trajecten.php';
require_once __DIR__ . '/../includes/requirements.php';
require_once __DIR__ . '/../includes/requirements_excel.php';
require_login();

$canEdit   = can('requirements.edit');
$trajectId = current_traject_id();
$traject   = $trajectId ? db_one('SELECT * FROM trajecten WHERE id = :id', [':id' => $trajectId]) : null;
if (!$traject) {
    flash_set('error', 'Kies eerst een traject op de requirements-pagina.');
    redirect('pages/requirements.php');
}

$action = input_str('action');

// ─── Exports via GET ─────────────────────────────────────────────────────────
if ($action === 'export') {
    $safe = preg_replace('/[^a-z0-9]+/i', '_', $traject['name']);
    requirements_excel_export((int)$trajectId, "requirements-{$safe}.xlsx");
    exit;
}
if ($action === 'template') {
    $safe = preg_replace('/[^a-z0-9]+/i', '_', $traject['name']);
    requirements_excel_template((int)$trajectId, "requirements-template-{$safe}.xlsx");
    exit;
}

// ─── Upload-verwerking ───────────────────────────────────────────────────────
$uploadResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    if (!$canEdit) { http_response_code(403); exit('Onvoldoende rechten.'); }

    if (requirements_scoring_locked((int)$trajectId)) {
        flash_set('error', 'Upload geblokkeerd: er is voor dit traject al een scoringsronde geopend of afgesloten.');
        redirect('pages/requirements_tools.php');
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'Upload mislukt. Kies een geldig .xlsx-bestand.');
        redirect('pages/requirements_tools.php');
    }
    $uploadResult = requirements_excel_import((int)$trajectId, $_FILES['file']['tmp_name']);
    if ($uploadResult['ok']) {
        audit_log('requirements_upload', 'traject', (int)$trajectId,
                  sprintf('rows=%d, created=%d, updated=%d',
                          $uploadResult['rows'], $uploadResult['created'], $uploadResult['updated']));
    }
}

// ─── Duplicaat-scan (on-demand via ?scan=1) ──────────────────────────────────
$scan = (int)input_int('scan', 0) === 1;
$dupGroups = $scan ? requirements_find_duplicates((int)$trajectId) : [];

$locked = requirements_scoring_locked((int)$trajectId);

$pageTitle  = 'Requirements-tools — ' . $traject['name'];
$currentNav = 'requirements';

$bodyRenderer = function () use ($traject, $trajectId, $canEdit, $locked, $uploadResult, $scan, $dupGroups) { ?>
  <div class="page-header">
    <div>
      <div class="row-sm" style="align-items:center;margin-bottom:4px;">
        <a href="<?= h(APP_BASE_URL) ?>/pages/requirements.php" class="muted small">← Requirements</a>
      </div>
      <h1>Tools</h1>
      <p class="muted small">Traject: <strong><?= h($traject['name']) ?></strong></p>
    </div>
  </div>

  <!-- Exports -->
  <div class="card">
    <div class="card-title"><h2>Exporteren</h2></div>
    <p class="muted small" style="margin-top:0;">
      Download alle requirements als Excel, of een lege template met geldige categorie-namen.
    </p>
    <div class="row-sm">
      <a class="btn" href="?action=export"><?= icon('file-text', 14) ?> Download requirements</a>
      <a class="btn ghost" href="?action=template"><?= icon('file-text', 14) ?> Download template</a>
    </div>
  </div>

  <!-- Import -->
  <div class="card">
    <div class="card-title"><h2>Importeren</h2></div>
    <p class="muted small" style="margin-top:0;">
      Zo werkt de upload:
    </p>
    <ul class="muted small" style="margin:0 0 10px 18px;padding:0;">
      <li>Kolomvolgorde (rij 1): <code>code, hoofdcategorie, subcategorie, titel, omschrijving, type</code>.</li>
      <li>Lege <code>code</code> → nieuw requirement; code wordt automatisch toegekend per hoofdcategorie (FR-, NFR-, VEND-, LIC-, SUP-).</li>
      <li>Ingevulde <code>code</code> → update op het bestaande requirement met die code.</li>
      <li>Onbekende hoofd- of subcategorie-naam, onbekende code, of onbekend type → upload wordt volledig afgekeurd; 0 mutaties.</li>
      <li><strong>Alles-of-niets</strong>: één fout blokkeert de hele upload.</li>
      <li>Upload is geblokkeerd zodra er voor dit traject een scoringsronde is geopend of afgesloten.</li>
    </ul>

    <?php if ($locked): ?>
      <div class="flash flash-error" style="margin-top:10px;">
        Upload geblokkeerd — er is al een scoringsronde geopend of afgesloten voor dit traject.
      </div>
    <?php endif; ?>

    <?php if ($uploadResult): ?>
      <?php if ($uploadResult['ok']): ?>
        <div class="flash flash-success" style="margin-top:10px;">
          Upload verwerkt: <?= (int)$uploadResult['created'] ?> aangemaakt,
          <?= (int)$uploadResult['updated'] ?> bijgewerkt (<?= (int)$uploadResult['rows'] ?> datarijen).
        </div>
      <?php else: ?>
        <div class="flash flash-error" style="margin-top:10px;">
          <strong>Upload afgekeurd — 0 mutaties uitgevoerd.</strong>
          <ul style="margin:6px 0 0 18px;padding:0;">
            <?php foreach ($uploadResult['errors'] as $e): ?>
              <li><?= h($e) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($canEdit && !$locked): ?>
      <form method="post" enctype="multipart/form-data" style="margin-top:10px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload">
        <div class="row-sm" style="align-items:center;gap:10px;">
          <input type="file" name="file" accept=".xlsx" required>
          <button type="submit" class="btn"><?= icon('check', 14) ?> Uploaden</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <!-- Duplicate scan -->
  <div class="card">
    <div class="card-title"><h2>Duplicaten &amp; overlappingen</h2></div>
    <p class="muted small" style="margin-top:0;">
      Scan binnen dit traject op exacte duplicaten (identieke titel) en fuzzy overlap (≥80% gelijkenis).
    </p>
    <form method="get">
      <input type="hidden" name="scan" value="1">
      <button type="submit" class="btn"><?= icon('search', 14) ?> Nu scannen</button>
    </form>

    <?php if ($scan): ?>
      <?php if (!$dupGroups): ?>
        <div class="flash flash-success" style="margin-top:10px;">
          Geen duplicaten of overlap gevonden. 👏
        </div>
      <?php else: ?>
        <p class="muted small" style="margin-top:14px;">
          <strong><?= count($dupGroups) ?></strong> groep(en) gevonden.
        </p>
        <?php foreach ($dupGroups as $g):
          $cls = $g['kind'] === 'exact' ? 'dup-exact' : 'dup-fuzzy';
          $label = $g['kind'] === 'exact'
              ? '<span class="badge red">Exact</span>'
              : '<span class="badge amber">Fuzzy</span>';
        ?>
          <div class="<?= h($cls) ?>"
               style="border:1px solid var(--gray-200); border-left-width:4px;
                      border-left-color:<?= $g['kind']==='exact' ? 'var(--red-600)' : 'var(--amber-600)' ?>;
                      border-radius:var(--radius); padding:10px 12px; margin-top:10px;">
            <div class="row-sm" style="margin-bottom:6px;align-items:center;">
              <?= $label ?>
              <span class="muted small"><?= count($g['reqs']) ?> requirements</span>
            </div>
            <table class="table" style="margin:0;">
              <thead><tr>
                <th style="width:90px;">Code</th>
                <th>Titel</th>
                <th style="width:240px;">Hoofd → sub</th>
              </tr></thead>
              <tbody>
                <?php foreach ($g['reqs'] as $r):
                  $href = APP_BASE_URL . '/pages/requirement_edit.php?id=' . (int)$r['id']; ?>
                  <tr class="row-link" data-href="<?= h($href) ?>">
                    <td><code><?= h($r['code']) ?></code></td>
                    <td><?= h($r['title']) ?></td>
                    <td class="muted small"><?= h($r['cat_name']) ?> → <?= h($r['sub_name']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php };

require __DIR__ . '/../templates/layout.php';
