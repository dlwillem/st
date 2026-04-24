<?php
/**
 * Demo-vragenlijst (per-traject kopie + globale master).
 *
 * Master:      demo_question_catalog      (beheer via Structuur stamdata)
 * Per traject: traject_demo_questions     (kopie bij traject-aanmaak, daarna muteerbaar)
 *
 * 5 blokken:
 *   1. Demo zelf      — 1–5 score, telt mee
 *   2. Systeem        — 1–5 score, telt mee
 *   3. Risico         — 1–5 score, risico-indicator (niet in totaal)
 *   4. Partner        — 1–5 score, telt mee
 *   5. Open vragen    — tekst, telt niet, toelichting altijd optioneel
 *
 * Score-methodiek blijft: avg per vraag → avg per blok → avg(blok1,2,4).
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

const DEMO_BLOCKS = [
    1 => ['title' => 'De demo zelf',  'subtitle' => 'Was dit een eerlijke, realistische demo?',          'in_total' => true,  'type' => 'score'],
    2 => ['title' => 'Het systeem',   'subtitle' => 'Wat voor indruk gaf het product?',                  'in_total' => true,  'type' => 'score'],
    3 => ['title' => 'Risico-check',  'subtitle' => 'Wat zie je nu dat je straks waarschijnlijk raakt?', 'in_total' => false, 'type' => 'score'],
    4 => ['title' => 'De partner',    'subtitle' => 'Wil je met deze club de komende jaren door?',      'in_total' => true,  'type' => 'score'],
    5 => ['title' => 'Open vragen',   'subtitle' => 'Open antwoorden — geen score, toelichting optioneel.', 'in_total' => false, 'type' => 'open'],
];

const DEMO_RISK_THRESHOLD = 3.0;

function demo_block_is_open(int $block): bool {
    return (DEMO_BLOCKS[$block]['type'] ?? 'score') === 'open';
}

/** Welke tabel — master (trajectId=null) of per-traject. */
function demo_qtable(?int $trajectId): string {
    return $trajectId === null ? 'demo_question_catalog' : 'traject_demo_questions';
}

/**
 * Catalog ophalen, gegroepeerd per blok.
 * trajectId = null → master; anders de per-traject kopie.
 */
function demo_catalog_grouped(?int $trajectId = null, bool $onlyActive = true): array {
    $table = demo_qtable($trajectId);
    $sql   = "SELECT id, block, sort_order, text, active FROM $table";
    $args  = [];
    $where = [];
    if ($trajectId !== null) { $where[] = 'traject_id = :t'; $args[':t'] = $trajectId; }
    if ($onlyActive)         { $where[] = 'active = 1'; }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql  .= ' ORDER BY block, sort_order, id';
    $rows  = db_all($sql, $args);

    $out = [];
    foreach (array_keys(DEMO_BLOCKS) as $b) $out[$b] = ['block' => $b, 'questions' => []];
    foreach ($rows as $r) {
        $b = (int)$r['block'];
        if (!isset($out[$b])) $out[$b] = ['block' => $b, 'questions' => []];
        $out[$b]['questions'][] = [
            'id'         => (int)$r['id'],
            'block'      => $b,
            'sort_order' => (int)$r['sort_order'],
            'text'       => (string)$r['text'],
            'active'     => (int)$r['active'] === 1,
        ];
    }
    return $out;
}

/** Platte lijst actieve vragen voor de scoringslus van een traject. */
function demo_catalog_active(int $trajectId): array {
    return db_all(
        'SELECT id, block, sort_order, text
           FROM traject_demo_questions
          WHERE traject_id = :t AND active = 1
          ORDER BY block, sort_order, id',
        [':t' => $trajectId]
    );
}

function demo_catalog_get(?int $trajectId, int $id): ?array {
    $table = demo_qtable($trajectId);
    $sql   = "SELECT * FROM $table WHERE id = :id";
    $args  = [':id' => $id];
    if ($trajectId !== null) { $sql .= ' AND traject_id = :t'; $args[':t'] = $trajectId; }
    $r = db_one($sql, $args);
    return $r ?: null;
}

function demo_catalog_create(?int $trajectId, int $block, string $text, ?int $sortOrder = null): int {
    if (!isset(DEMO_BLOCKS[$block])) throw new RuntimeException('Ongeldig blok.');
    $text = trim($text);
    if ($text === '') throw new RuntimeException('Tekst is verplicht.');

    $table = demo_qtable($trajectId);
    if ($sortOrder === null) {
        $sql = "SELECT COALESCE(MAX(sort_order),0)+10 FROM $table WHERE block = :b";
        $args = [':b' => $block];
        if ($trajectId !== null) { $sql .= ' AND traject_id = :t'; $args[':t'] = $trajectId; }
        $sortOrder = (int)db_value($sql, $args);
    }
    $now = date('Y-m-d H:i:s');
    $data = [
        'block'      => $block,
        'sort_order' => $sortOrder,
        'text'       => $text,
        'active'     => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    if ($trajectId !== null) $data['traject_id'] = $trajectId;
    $id = db_insert($table, $data);
    audit_log('demo_question_created', ($trajectId === null ? 'demo_question' : 'traject_demo_question'), $id,
              ($trajectId ? "traject=$trajectId · " : '') . substr($text, 0, 80));
    return $id;
}

function demo_catalog_update(?int $trajectId, int $id, int $block, string $text, bool $active): void {
    if (!isset(DEMO_BLOCKS[$block])) throw new RuntimeException('Ongeldig blok.');
    $text = trim($text);
    if ($text === '') throw new RuntimeException('Tekst is verplicht.');

    $table = demo_qtable($trajectId);
    $where = 'id = :id';
    $args  = [':id' => $id];
    if ($trajectId !== null) { $where .= ' AND traject_id = :t'; $args[':t'] = $trajectId; }
    db_update($table, [
        'block'      => $block,
        'text'       => $text,
        'active'     => $active ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s'),
    ], $where, $args);
    audit_log('demo_question_updated', ($trajectId === null ? 'demo_question' : 'traject_demo_question'), $id,
              substr($text, 0, 80));
}

function demo_catalog_delete(?int $trajectId, int $id): void {
    // Check: scores of open-antwoorden aanwezig?
    if ($trajectId === null) {
        // Master: verwijderen mag alleen als geen enkel traject een kopie nog gebruikt in scores.
        $used = (int)db_value(
            'SELECT COUNT(*) FROM demo_scores s
               JOIN traject_demo_questions tdq ON tdq.id = s.question_id
              WHERE tdq.source_catalog_id = :q', [':q' => $id]
        );
        $usedOpen = (int)db_value(
            'SELECT COUNT(*) FROM demo_open_scores s
               JOIN traject_demo_questions tdq ON tdq.id = s.question_id
              WHERE tdq.source_catalog_id = :q', [':q' => $id]
        );
        if ($used + $usedOpen > 0) {
            throw new RuntimeException('Master-vraag in gebruik bij traject-kopie met scores — deactiveer hem.');
        }
        db_exec('DELETE FROM demo_question_catalog WHERE id = :id', [':id' => $id]);
    } else {
        $used = (int)db_value('SELECT COUNT(*) FROM demo_scores WHERE question_id = :q', [':q' => $id]);
        $usedOpen = (int)db_value('SELECT COUNT(*) FROM demo_open_scores WHERE question_id = :q', [':q' => $id]);
        if ($used + $usedOpen > 0) {
            throw new RuntimeException('Deze vraag heeft al scores/antwoorden — deactiveer hem in plaats van verwijderen.');
        }
        db_exec('DELETE FROM traject_demo_questions WHERE id = :id AND traject_id = :t',
                [':id' => $id, ':t' => $trajectId]);
    }
    audit_log('demo_question_deleted', ($trajectId === null ? 'demo_question' : 'traject_demo_question'), $id, '');
}

function demo_catalog_reorder(?int $trajectId, int $id, int $sortOrder): void {
    $table = demo_qtable($trajectId);
    $where = 'id = :id';
    $args  = [':id' => $id];
    if ($trajectId !== null) { $where .= ' AND traject_id = :t'; $args[':t'] = $trajectId; }
    db_update($table, [
        'sort_order' => $sortOrder,
        'updated_at' => date('Y-m-d H:i:s'),
    ], $where, $args);
}

/**
 * Kopieer master-catalog naar een nieuw traject. Wordt aangeroepen bij traject_create().
 * Idempotent: doet niets als het traject al een kopie heeft.
 */
function demo_catalog_copy_from_master(int $trajectId): int {
    $have = (int)db_value('SELECT COUNT(*) FROM traject_demo_questions WHERE traject_id = :t',
                          [':t' => $trajectId]);
    if ($have > 0) return 0;
    $master = db_all('SELECT id, block, sort_order, text, active
                        FROM demo_question_catalog
                       ORDER BY block, sort_order, id');
    $now = date('Y-m-d H:i:s');
    $n = 0;
    foreach ($master as $m) {
        db_insert('traject_demo_questions', [
            'traject_id'        => $trajectId,
            'block'             => (int)$m['block'],
            'sort_order'        => (int)$m['sort_order'],
            'text'              => (string)$m['text'],
            'active'            => (int)$m['active'],
            'source_catalog_id' => (int)$m['id'],
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
        $n++;
    }
    audit_log('demo_catalog_copied', 'traject', $trajectId, "$n vragen uit master");
    return $n;
}

// ─── Scoren (1–5) ───────────────────────────────────────────────────────────

/**
 * @return array<int,int> [question_id => score 1..5]
 */
function demo_scores_for_deelnemer(int $deelnemerId): array {
    $rows = db_all(
        'SELECT question_id, score FROM demo_scores WHERE deelnemer_id = :d',
        [':d' => $deelnemerId]
    );
    $out = [];
    foreach ($rows as $r) $out[(int)$r['question_id']] = (int)$r['score'];
    return $out;
}

function demo_score_upsert(int $rondeId, int $questionId, int $deelnemerId, int $score): void {
    $score = max(1, min(5, $score));
    $existing = db_value(
        'SELECT id FROM demo_scores
          WHERE ronde_id = :r AND question_id = :q AND deelnemer_id = :d',
        [':r' => $rondeId, ':q' => $questionId, ':d' => $deelnemerId]
    );
    $now = date('Y-m-d H:i:s');
    if ($existing) {
        db_update('demo_scores', [
            'score'      => $score,
            'updated_at' => $now,
        ], 'id = :id', [':id' => (int)$existing]);
    } else {
        db_insert('demo_scores', [
            'ronde_id'     => $rondeId,
            'question_id'  => $questionId,
            'deelnemer_id' => $deelnemerId,
            'score'        => $score,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
}

// ─── Open vragen (blok 5, tekst) ────────────────────────────────────────────

/**
 * @return array<int,string> [question_id => antwoord-tekst]
 */
function demo_open_scores_for_deelnemer(int $rondeId, int $deelnemerId): array {
    $rows = db_all(
        'SELECT question_id, answer_text
           FROM demo_open_scores
          WHERE ronde_id = :r AND deelnemer_id = :d',
        [':r' => $rondeId, ':d' => $deelnemerId]
    );
    $out = [];
    foreach ($rows as $r) $out[(int)$r['question_id']] = (string)$r['answer_text'];
    return $out;
}

/**
 * Sla een open antwoord op. Lege tekst → bestaande rij verwijderen (niets bewaren).
 */
function demo_open_score_upsert(int $rondeId, int $questionId, int $deelnemerId, string $text): void {
    $text = trim($text);
    $existing = db_value(
        'SELECT id FROM demo_open_scores
          WHERE ronde_id = :r AND question_id = :q AND deelnemer_id = :d',
        [':r' => $rondeId, ':q' => $questionId, ':d' => $deelnemerId]
    );
    $now = date('Y-m-d H:i:s');
    if ($text === '') {
        if ($existing) {
            db_exec('DELETE FROM demo_open_scores WHERE id = :id', [':id' => (int)$existing]);
        }
        return;
    }
    if ($existing) {
        db_update('demo_open_scores', [
            'answer_text' => $text,
            'updated_at'  => $now,
        ], 'id = :id', [':id' => (int)$existing]);
    } else {
        db_insert('demo_open_scores', [
            'ronde_id'     => $rondeId,
            'question_id'  => $questionId,
            'deelnemer_id' => $deelnemerId,
            'answer_text'  => $text,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
}

/**
 * Alle open antwoorden voor een ronde, voor rapportage.
 * @return array<int, array{question_id:int, question_text:string, sort_order:int,
 *                          answers: array<int, array{deelnemer_id:int, name:string, text:string}>}>
 *          geïndexeerd op question_id, gesorteerd op sort_order.
 */
function demo_open_scores_list(int $rondeId): array {
    $rows = db_all(
        'SELECT os.question_id, os.deelnemer_id, os.answer_text,
                q.text AS question_text, q.sort_order,
                sd.name, sd.email
           FROM demo_open_scores os
           JOIN traject_demo_questions q ON q.id = os.question_id
           JOIN scoring_deelnemers sd   ON sd.id = os.deelnemer_id
          WHERE os.ronde_id = :r
          ORDER BY q.sort_order, q.id, sd.name',
        [':r' => $rondeId]
    );
    $out = [];
    foreach ($rows as $r) {
        $qid = (int)$r['question_id'];
        if (!isset($out[$qid])) {
            $out[$qid] = [
                'question_id'   => $qid,
                'question_text' => (string)$r['question_text'],
                'sort_order'    => (int)$r['sort_order'],
                'answers'       => [],
            ];
        }
        $out[$qid]['answers'][] = [
            'deelnemer_id' => (int)$r['deelnemer_id'],
            'name'         => (string)$r['name'],
            'text'         => (string)$r['answer_text'],
        ];
    }
    return $out;
}

// ─── Rapportage ────────────────────────────────────────────────────────────

function demo_compute_round_score(int $rondeId): array {
    $rows = db_all(
        'SELECT s.question_id, q.block, AVG(s.score) AS avg_score, COUNT(*) AS n
           FROM demo_scores s
           JOIN traject_demo_questions q ON q.id = s.question_id
          WHERE s.ronde_id = :r
          GROUP BY s.question_id, q.block',
        [':r' => $rondeId]
    );
    $blocks = [];
    foreach (array_keys(DEMO_BLOCKS) as $b) $blocks[$b] = ['sum' => 0.0, 'n' => 0];
    foreach ($rows as $r) {
        $b = (int)$r['block'];
        if (demo_block_is_open($b)) continue;
        $blocks[$b]['sum'] += (float)$r['avg_score'];
        $blocks[$b]['n']   += 1;
    }
    $blockOut = [];
    $totalSum = 0.0; $totalCnt = 0;
    $riskAvg = null;
    foreach ($blocks as $b => $bd) {
        if (demo_block_is_open($b)) { $blockOut[$b] = ['avg' => null, 'n' => 0]; continue; }
        $avg = $bd['n'] > 0 ? $bd['sum'] / $bd['n'] : null;
        $blockOut[$b] = ['avg' => $avg, 'n' => $bd['n']];
        if (DEMO_BLOCKS[$b]['in_total'] && $avg !== null) { $totalSum += $avg; $totalCnt++; }
        if (!DEMO_BLOCKS[$b]['in_total']) $riskAvg = $avg;
    }
    $total = $totalCnt > 0 ? $totalSum / $totalCnt : null;
    $riskFlag = $riskAvg !== null && $riskAvg < DEMO_RISK_THRESHOLD;

    $nDeeln = (int)db_value(
        'SELECT COUNT(DISTINCT deelnemer_id) FROM demo_scores WHERE ronde_id = :r',
        [':r' => $rondeId]
    );
    return [
        'total'        => $total,
        'risk'         => $riskAvg,
        'risk_flag'    => $riskFlag,
        'blocks'       => $blockOut,
        'n_deelnemers' => $nDeeln,
    ];
}
