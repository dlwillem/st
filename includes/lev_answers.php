<?php
/**
 * Leverancier-antwoorden: upload-metadata, auto-score-engine, KO-enforcement.
 *
 * Auto-score-regels (één antwoord per requirement per leverancier):
 *   - answer_choice = 'volledig' + geen toelichting/bewijs  → auto max (5)
 *   - answer_choice = 'niet'     + geen toelichting/bewijs  → auto min (1)
 *       · uitzondering: requirements.type = 'ko' → leverancier wordt afgewezen
 *   - answer_choice = 'niet'     + KO (met of zonder tekst) → flag voor manual review
 *   - answer_choice = 'deels'                               → manual (altijd)
 *   - answer_choice = 'nvt'                                 → niet gescoord
 *   - overige combinaties met toelichting/bewijs            → manual
 *
 * Toelichting geldt als "aanwezig" bij trim-lengte >= LEV_ANSWER_MIN_TOELICHTING
 * of als evidence_url niet leeg is.
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

const LEV_ANSWER_MIN_TOELICHTING = 5;
const LEV_SCORE_AUTO_MAX = 5;
const LEV_SCORE_AUTO_MIN = 1;

/**
 * Heeft de leverancier al een upload geregistreerd?
 */
function lev_upload_get(int $leverancierId): ?array {
    return db_one(
        'SELECT u.*, usr.name AS uploaded_by_name
           FROM leverancier_uploads u
           LEFT JOIN users usr ON usr.id = u.uploaded_by
          WHERE u.leverancier_id = :l',
        [':l' => $leverancierId]
    );
}

/**
 * Zijn er al (handmatige) scores voor deze leverancier? Zo ja: upload is gelocked.
 * Auto-scores (source='auto') blokkeren NIET — die zijn door ons zelf gezet en
 * worden bij re-upload overschreven.
 */
function lev_scoring_started(int $leverancierId): bool {
    $n = (int)db_value(
        "SELECT COUNT(*) FROM scores
          WHERE leverancier_id = :l
            AND (source = 'manual' OR deelnemer_id IS NOT NULL)",
        [':l' => $leverancierId]
    );
    if ($n > 0) return true;
    // Ook: als er een ronde gesloten is voor deze leverancier, upload dicht.
    $c = (int)db_value(
        "SELECT COUNT(*) FROM scoring_rondes
          WHERE leverancier_id = :l AND status = 'gesloten'",
        [':l' => $leverancierId]
    );
    return $c > 0;
}

/**
 * Bepaalt of een antwoordregel "toelichting aanwezig" heeft.
 */
function lev_answer_has_explanation(?string $text, ?string $evidence): bool {
    if ($evidence !== null && trim((string)$evidence) !== '') return true;
    $t = trim((string)($text ?? ''));
    return mb_strlen($t) >= LEV_ANSWER_MIN_TOELICHTING;
}

/**
 * Classificeert één antwoordregel.
 * Return: ['class' => 'auto_max'|'auto_min'|'manual'|'skip'|'ko_fail_auto'|'ko_manual',
 *          'score' => int|null, 'reason' => string]
 */
function lev_classify_answer(array $answer, array $requirement): array {
    $choice    = (string)($answer['answer_choice'] ?? '');
    $hasExpl   = lev_answer_has_explanation($answer['answer_text'] ?? null, $answer['evidence_url'] ?? null);
    $isKO      = (string)($requirement['type'] ?? '') === 'ko';

    if ($choice === 'nvt') {
        return ['class' => 'skip', 'score' => null, 'reason' => 'N.v.t. — niet gescoord'];
    }

    if ($choice === 'deels') {
        return ['class' => 'manual', 'score' => null, 'reason' => '"Deels" vereist altijd handmatige score'];
    }

    if ($choice === 'volledig') {
        if ($hasExpl) {
            return ['class' => 'manual', 'score' => null, 'reason' => 'Ja + toelichting — handmatig beoordelen'];
        }
        return ['class' => 'auto_max', 'score' => LEV_SCORE_AUTO_MAX, 'reason' => 'Ja zonder toelichting — auto max'];
    }

    if ($choice === 'niet') {
        if ($isKO && !$hasExpl) {
            return ['class' => 'ko_fail_auto', 'score' => LEV_SCORE_AUTO_MIN, 'reason' => 'Knock-out: Nee zonder toelichting'];
        }
        if ($isKO && $hasExpl) {
            return ['class' => 'ko_manual', 'score' => null, 'reason' => 'Knock-out: Nee met toelichting — handmatig beslissen'];
        }
        if ($hasExpl) {
            return ['class' => 'manual', 'score' => null, 'reason' => 'Nee + toelichting — handmatig beoordelen'];
        }
        return ['class' => 'auto_min', 'score' => LEV_SCORE_AUTO_MIN, 'reason' => 'Nee zonder toelichting — auto min'];
    }

    return ['class' => 'skip', 'score' => null, 'reason' => 'Onbekend of leeg antwoord'];
}

/**
 * Haalt alle antwoorden + bijbehorende requirements op voor een leverancier.
 * Retourneert per rij: answer-velden + requirement-velden (type, code, scope=categorie_code).
 */
function lev_answers_full(int $leverancierId, int $trajectId): array {
    return db_all(
        'SELECT a.requirement_id, a.answer_choice, a.answer_text, a.evidence_url, a.updated_at AS answered_at,
                r.code, r.title, r.type, r.subcategorie_id,
                s.name AS subcategorie_naam,
                c.code AS scope, c.name AS categorie_naam
           FROM leverancier_answers a
           JOIN requirements  r ON r.id = a.requirement_id
           JOIN subcategorieen s ON s.id = r.subcategorie_id
           JOIN categorieen    c ON c.id = s.categorie_id
          WHERE a.leverancier_id = :l AND a.traject_id = :t
          ORDER BY c.code, s.name, r.code',
        [':l' => $leverancierId, ':t' => $trajectId]
    );
}

/**
 * Dry-run: classificeer alle antwoorden, retourneer counts + per-rij classificatie.
 */
function lev_auto_score_dry_run(int $leverancierId, int $trajectId): array {
    $rows = lev_answers_full($leverancierId, $trajectId);
    $counts = [
        'total' => count($rows), 'auto_max' => 0, 'auto_min' => 0,
        'manual' => 0, 'skip' => 0, 'ko_fail_auto' => 0, 'ko_manual' => 0,
    ];
    $classified = [];
    foreach ($rows as $r) {
        $req = ['type' => $r['type']];
        $ans = ['answer_choice' => $r['answer_choice'], 'answer_text' => $r['answer_text'], 'evidence_url' => $r['evidence_url']];
        $c   = lev_classify_answer($ans, $req);
        $classified[] = $r + $c;
        if (isset($counts[$c['class']])) $counts[$c['class']]++;
    }
    return ['counts' => $counts, 'rows' => $classified];
}

/**
 * Voert auto-scoring commit uit: maakt rondes per scope (indien nog niet bestaand),
 * schrijft systeem-scores (source='auto', deelnemer_id NULL). Markeert leverancier
 * als 'afgewezen' indien er ko_fail_auto-regels zijn.
 *
 * Vereist dat scoring nog niet begonnen is; caller moet dit controleren.
 */
function lev_auto_score_commit(int $leverancierId, int $trajectId): array {
    $dry = lev_auto_score_dry_run($leverancierId, $trajectId);
    $now = date('Y-m-d H:i:s');
    $rondeCache = []; // scope → ronde_id

    $koFails = [];

    db_transaction(function () use ($dry, $leverancierId, $trajectId, $now, &$rondeCache, &$koFails) {
        // Eerst alle bestaande AUTO-scores voor deze leverancier opruimen,
        // zodat re-upload een schone staat krijgt. Handmatige scores blijven staan
        // (die kunnen er niet zijn — caller heeft dat al gechecked).
        db_exec(
            "DELETE FROM scores WHERE leverancier_id = :l AND source = 'auto'",
            [':l' => $leverancierId]
        );

        // Ronde aanmaken voor ELKE scope met antwoorden — ook als er alleen
        // manual/ko_manual/skip-rijen zijn (anders geen scoring-concept voor die scope).
        foreach ($dry['rows'] as $r) {
            $scope = (string)$r['scope'];
            if ($scope !== '' && !isset($rondeCache[$scope])) {
                $rondeCache[$scope] = ronde_upsert($trajectId, $leverancierId, $scope);
            }
        }

        foreach ($dry['rows'] as $r) {
            $cls   = $r['class'];
            $score = $r['score'];
            if ($score === null) continue; // alleen auto_max/auto_min/ko_fail_auto schrijven

            $scope = (string)$r['scope'];
            $rid = (int)$rondeCache[$scope];

            // Insert direct (niet score_upsert), want we willen source='auto' zetten
            // en deelnemer_id IS NULL uniek-match.
            $existingId = db_value(
                'SELECT id FROM scores
                  WHERE ronde_id = :r AND leverancier_id = :l
                    AND requirement_id = :q AND deelnemer_id IS NULL',
                [':r' => $rid, ':l' => $leverancierId, ':q' => (int)$r['requirement_id']]
            );
            if ($existingId) {
                db_update('scores', [
                    'score'      => (int)$score,
                    'notes'      => (string)$r['reason'],
                    'source'     => 'auto',
                    'updated_at' => $now,
                ], 'id = :id', [':id' => (int)$existingId]);
            } else {
                db_insert('scores', [
                    'ronde_id'       => $rid,
                    'leverancier_id' => $leverancierId,
                    'requirement_id' => (int)$r['requirement_id'],
                    'deelnemer_id'   => null,
                    'score'          => (int)$score,
                    'notes'          => (string)$r['reason'],
                    'source'         => 'auto',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }

            if ($cls === 'ko_fail_auto') {
                $koFails[] = sprintf('%s — %s', (string)$r['code'], (string)$r['title']);
            }
        }

        if ($koFails) {
            $reason = "KO gefaald: " . implode(' | ', $koFails);
            // Niet direct afwijzen — zet op 'onder_review' zodat beoordelaar
            // zelf beslist. Respecteer een eventueel reeds handmatig gezette
            // 'afgewezen'-status.
            db_exec(
                "UPDATE leveranciers
                    SET ko_failed_reason = :r,
                        status = CASE WHEN status = 'afgewezen' THEN status ELSE 'onder_review' END
                  WHERE id = :id",
                [':r' => $reason, ':id' => $leverancierId]
            );
            audit_log('leverancier_ko_flagged', 'leverancier', $leverancierId, $reason);
        }
    });

    audit_log('leverancier_auto_scored', 'leverancier', $leverancierId,
        sprintf('auto_max=%d, auto_min=%d, manual=%d, skip=%d, ko_fail=%d, ko_manual=%d',
            $dry['counts']['auto_max'], $dry['counts']['auto_min'],
            $dry['counts']['manual'], $dry['counts']['skip'],
            $dry['counts']['ko_fail_auto'], $dry['counts']['ko_manual']));

    return ['counts' => $dry['counts'], 'ko_fails' => $koFails];
}

/**
 * Verwijdert alle leverancier-antwoorden + upload-metadata + auto-scores voor een leverancier.
 * Alleen toegestaan wanneer scoring nog niet begonnen is.
 */
function lev_upload_delete(int $leverancierId): void {
    if (lev_scoring_started($leverancierId)) {
        throw new RuntimeException('Upload kan niet verwijderd worden — er zijn al handmatige scores ingevoerd.');
    }
    $u = lev_upload_get($leverancierId);
    db_transaction(function () use ($leverancierId, $u) {
        db_exec("DELETE FROM scores WHERE leverancier_id = :l AND source = 'auto'", [':l' => $leverancierId]);
        db_exec('DELETE FROM leverancier_answers WHERE leverancier_id = :l', [':l' => $leverancierId]);
        db_exec('DELETE FROM leverancier_uploads WHERE leverancier_id = :l', [':l' => $leverancierId]);
        // Reset status als door KO gevlagd (onder_review of afgewezen), daarna ko_failed_reason wissen.
        db_exec("UPDATE leveranciers
                    SET status = CASE
                            WHEN ko_failed_reason IS NOT NULL
                                 AND status IN ('onder_review','afgewezen')
                            THEN 'actief'
                            ELSE status
                         END,
                        ko_failed_reason = NULL
                  WHERE id = :id", [':id' => $leverancierId]);
    });
    if ($u && !empty($u['stored_path']) && file_exists($u['stored_path'])) {
        @unlink($u['stored_path']);
    }
    audit_log('leverancier_upload_deleted', 'leverancier', $leverancierId, $u['original_name'] ?? '');
}

/**
 * Registreert upload-metadata (na succesvolle import + auto-score).
 * Upsert op leverancier_id (1 upload per leverancier).
 */
function lev_upload_record(int $leverancierId, int $trajectId, string $originalName,
                           string $storedPath, array $counts): void {
    $existing = db_value('SELECT id FROM leverancier_uploads WHERE leverancier_id = :l',
        [':l' => $leverancierId]);
    $userId = (int)(current_user()['id'] ?? 0) ?: null;
    $now = date('Y-m-d H:i:s');
    $data = [
        'traject_id'    => $trajectId,
        'leverancier_id'=> $leverancierId,
        'original_name' => $originalName,
        'stored_path'   => $storedPath,
        'uploaded_by'   => $userId,
        'uploaded_at'   => $now,
        'rows_total'    => (int)($counts['total'] ?? 0),
        'rows_auto'     => (int)($counts['auto_max'] ?? 0) + (int)($counts['auto_min'] ?? 0) + (int)($counts['ko_fail_auto'] ?? 0),
        'rows_manual'   => (int)($counts['manual'] ?? 0) + (int)($counts['ko_manual'] ?? 0),
        'rows_ko_fail'  => (int)($counts['ko_fail_auto'] ?? 0),
    ];
    if ($existing) {
        db_update('leverancier_uploads', $data, 'id = :id', [':id' => (int)$existing]);
    } else {
        db_insert('leverancier_uploads', $data);
    }
}
