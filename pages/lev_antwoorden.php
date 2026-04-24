<?php
/**
 * Bekijkt de door de leverancier ingeleverde antwoorden + classificatie (auto/manual/KO).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/leveranciers.php';
require_once __DIR__ . '/../includes/lev_answers.php';
require_once __DIR__ . '/../includes/leverancier_excel.php';
require_login();

$leverancierId = input_int('lev');
if (!$leverancierId) redirect('pages/trajecten.php');

$lev = db_one('SELECT * FROM leveranciers WHERE id = :id', [':id' => $leverancierId]);
if (!$lev) { flash_set('error', 'Leverancier niet gevonden.'); redirect('pages/trajecten.php'); }
$trajectId = (int)$lev['traject_id'];
if (!can_view_traject($trajectId)) { http_response_code(403); exit('Onvoldoende rechten.'); }
$traject   = db_one('SELECT name FROM trajecten WHERE id = :id', [':id' => $trajectId]);

$upload = lev_upload_get($leverancierId);
$dry    = lev_auto_score_dry_run($leverancierId, $trajectId);

$filter = input_str('cls');
$q      = input_str('q');

$rows = $dry['rows'];
if ($filter !== '') {
    $rows = array_values(array_filter($rows, fn($r) => $r['class'] === $filter));
}
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_values(array_filter($rows, function ($r) use ($needle) {
        return str_contains(mb_strtolower((string)$r['code']),  $needle)
            || str_contains(mb_strtolower((string)$r['title']), $needle)
            || str_contains(mb_strtolower((string)($r['answer_text'] ?? '')), $needle);
    }));
}

$classColors = [
    'auto_max' => '#10b981', 'auto_min' => '#6b7280',
    'manual'   => '#3b82f6', 'skip'     => '#9ca3af',
    'ko_fail_auto' => '#ef4444', 'ko_manual' => '#f59e0b',
];
$classLabels = [
    'auto_max' => 'Auto max', 'auto_min' => 'Auto min',
    'manual'   => 'Handmatig', 'skip' => 'N.v.t.',
    'ko_fail_auto' => 'KO gefaald', 'ko_manual' => 'KO handmatig',
];

$pageTitle  = 'Antwoorden — ' . $lev['name'];
$currentNav = 'trajecten';

$bodyRenderer = function () use (
    $lev, $traject, $trajectId, $upload, $dry, $rows, $filter, $q,
    $classColors, $classLabels
) {
    $c = $dry['counts'];
    $backUrl = APP_BASE_URL . '/pages/traject_detail.php?id=' . $trajectId . '&tab=leveranciers';
?>
  <div class="page-header">
    <div>
      <div class="row-sm" style="align-items:center;margin-bottom:4px;">
        <a href="<?= h($backUrl) ?>" class="muted small">← Terug naar leveranciers</a>
      </div>
      <div class="ph-title">Antwoorden van <?= h($lev['name']) ?></div>
      <div class="ph-sub">
        <?= h($traject['name'] ?? '') ?>
        <?php if ($upload): ?>
          · <?= h($upload['original_name']) ?>
          · geüpload <?= h(date('d-m-Y H:i', strtotime($upload['uploaded_at']))) ?>
          <?php if (!empty($upload['uploaded_by_name'])): ?> door <?= h($upload['uploaded_by_name']) ?><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <form method="get" class="sbar" style="margin-bottom:12px;">
    <input type="hidden" name="lev" value="<?= (int)$lev['id'] ?>">
    <div class="sinp-w" style="flex:1;min-width:200px;">
      <?= icon('search', 14) ?>
      <input type="text" class="sinp" name="q" value="<?= h($q) ?>" placeholder="Zoek op code, titel of toelichting…">
    </div>
    <select name="cls" class="fsel-sm" onchange="this.form.submit()">
      <option value="">Alle (<?= (int)$c['total'] ?>)</option>
      <option value="auto_max"     <?= $filter==='auto_max'?'selected':'' ?>>Auto max (<?= (int)$c['auto_max'] ?>)</option>
      <option value="auto_min"     <?= $filter==='auto_min'?'selected':'' ?>>Auto min (<?= (int)$c['auto_min'] ?>)</option>
      <option value="manual"       <?= $filter==='manual'?'selected':'' ?>>Handmatig (<?= (int)$c['manual'] ?>)</option>
      <option value="ko_manual"    <?= $filter==='ko_manual'?'selected':'' ?>>KO handmatig (<?= (int)$c['ko_manual'] ?>)</option>
      <option value="ko_fail_auto" <?= $filter==='ko_fail_auto'?'selected':'' ?>>KO gefaald (<?= (int)$c['ko_fail_auto'] ?>)</option>
      <option value="skip"         <?= $filter==='skip'?'selected':'' ?>>N.v.t. (<?= (int)$c['skip'] ?>)</option>
    </select>
    <button class="btn ghost" type="submit">Filter</button>
    <?php if ($filter !== '' || $q !== ''): ?>
      <a class="btn ghost" href="?lev=<?= (int)$lev['id'] ?>">Reset</a>
    <?php endif; ?>
  </form>

  <div class="sc">
    <div class="sc-body">
      <?php if (!$rows): ?>
        <p class="muted small" style="margin:0;">Geen antwoorden gevonden voor dit filter.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th style="width:10%;">Categorie</th>
                <th style="width:10%;">Code</th>
                <th>Titel</th>
                <th style="width:8%;">Antwoord</th>
                <th style="width:28%;">Toelichting / bewijs</th>
                <th style="width:14%;">Classificatie</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r):
                $cls = (string)$r['class'];
                $clr = $classColors[$cls] ?? '#9ca3af';
              ?>
                <tr>
                  <td class="small"><?= h($r['scope']) ?></td>
                  <td class="small"><code><?= h($r['code']) ?></code><?php if ($r['type'] === 'ko'): ?> <span class="badge red">KO</span><?php endif; ?></td>
                  <td class="small"><?= h($r['title']) ?></td>
                  <td class="small"><strong><?= h(lev_answer_label($r['answer_choice'] ?? '')) ?></strong></td>
                  <td class="small">
                    <?php if (!empty($r['answer_text'])): ?>
                      <div><?= nl2br(h($r['answer_text'])) ?></div>
                    <?php endif; ?>
                    <?php $evUrl = safe_url($r['evidence_url'] ?? null); if ($evUrl !== ''): ?>
                      <div class="muted" style="margin-top:4px;">
                        <a href="<?= h($evUrl) ?>" target="_blank" rel="noopener noreferrer">Bewijs ↗</a>
                      </div>
                    <?php endif; ?>
                    <?php if (empty($r['answer_text']) && empty($r['evidence_url'])): ?>
                      <span class="muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge" style="background:<?= h($clr) ?>;color:#fff;" title="<?= h($r['reason']) ?>">
                      <?= h($classLabels[$cls] ?? $cls) ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php };

require __DIR__ . '/../templates/layout.php';
