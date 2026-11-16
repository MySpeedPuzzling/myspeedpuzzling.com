<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\SuspiciousTimeImportCard;
use SpeedPuzzling\Web\Results\SuspiciousTimePuzzleCard;
use SpeedPuzzling\Web\Results\SuspiciousTimePuzzleCardCase;
use SpeedPuzzling\Web\Results\SuspiciousTimeQueueCounts;
use SpeedPuzzling\Web\Results\SuspiciousTimeQueuePage;
use SpeedPuzzling\Web\Value\ExpectedTimeSource;
use SpeedPuzzling\Web\Value\PuzzleSecrecy;
use SpeedPuzzling\Web\Value\SuspicionDirection;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeQueueTab;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeResponse;
use SpeedPuzzling\Web\Value\SuspiciousTimeTier;

/**
 * Which cases the time verification queue lists, in which order (docs/features/suspicious-time-review.md, "Moderator
 * queue"); GetSuspiciousTimeCaseDetail loads what a card shows. Reads only suspicious_time_* and what the cases point
 * at - admins and moderators see every queued time, a private player's included (Jan, 2026-10-07). Times on a secret
 * competition puzzle (PuzzleSecrecy) stay out until the reveal, like in every other moderator queue.
 *
 * "Too fast" / "Too slow" list pending cases of their direction: strong before possible, then by impact. For a fast
 * time the impact is its leaderboard place - counted exactly only for the cards of the listed page (an exact place
 * for every pending case costs ~45 ms on the production copy), so the order uses what puzzle_statistics already knows:
 * the time leads its puzzle (#1) first, then bigger leaderboards first, then the score. A slow time takes no place
 * anybody cares about - by score (how many times slower) after the tier.
 *
 * Pending cases on results that one "Add" of a player's spreadsheet import made are **one card** (import cards): a
 * history brought in at once is judged at once - how the imported times stand to the player's typed ones - instead
 * of flooding the list with hundreds of cards. A case finds its import through the import's row that names the
 * result it made (`data_import_row.solving_time_id`, read only): a typed result is no row's, so it is never in one.
 *
 * Pending cases of a puzzle that makes a puzzle card ("is the piece count right - or is it a hard puzzle?") are shown
 * in the card, not in the list: several different players raised on it in that direction making a fifth of its
 * comparable results (a popular puzzle collects a few raised times by chance), or its difficulty far below (fast) or
 * above (slow) the usual. Every case of a card is decided there like in the list; the card carries the puzzle's slow
 * threshold while it is for the current piece count.
 */
readonly final class GetSuspiciousTimeQueue
{
    public const int PER_PAGE = 30;
    // A puzzle card: this many different players raised on one puzzle in one direction ...
    public const int CARD_MIN_PLAYERS = 2;
    // ... whose raised results are at least this share of the puzzle's comparable results: its results of the types
    // raised (solo; pair/team below the slow floor) as puzzle_statistics counts them - not flagged, the raised included
    public const float CARD_MIN_SHARE = 0.2;
    // ... or players are usually this much faster on the puzzle than their level (puzzle_difficulty.difficulty_score)
    public const float CARD_EASY_BELOW = 0.5;
    // ... or this much slower - the mirror for the "Too slow" tab
    public const float CARD_HARD_ABOVE = 2.0;
    // An import card: this many pending cases of one direction on results one "Add" of a spreadsheet import made
    public const int IMPORT_CARD_MIN = 2;
    public const int IMPORT_CARD_EXAMPLES = 10;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function counts(): SuspiciousTimeQueueCounts
    {
        $notSecret = PuzzleSecrecy::sqlNotSecret('p');
        $query = <<<SQL
SELECT
    COUNT(*) FILTER (WHERE c.status = :pending AND c.direction = :fast) AS fast,
    COUNT(*) FILTER (WHERE c.status = :pending AND c.direction = :slow) AS slow,
    COUNT(*) FILTER (WHERE c.status = :marked AND (c.player_edited_at IS NOT NULL OR EXISTS (
        SELECT 1 FROM suspicious_time_notice n
        WHERE n.case_id = c.id AND n.marked_at = c.marked_at AND n.response = :saysCorrect AND n.answer IS NULL
    ))) AS replied,
    COUNT(*) FILTER (WHERE c.status = :marked) AS marked,
    COUNT(*) FILTER (WHERE c.status = :trusted) AS trusted,
    (SELECT COUNT(*) FROM suspicious_time_decision) AS decisions
FROM suspicious_time_case c
JOIN puzzle_solving_time pst ON pst.id = c.time_id
JOIN puzzle p ON p.id = pst.puzzle_id
WHERE {$notSecret}
SQL;

        /** @var array{fast: int|string, slow: int|string, replied: int|string, marked: int|string, trusted: int|string, decisions: int|string} $row */
        $row = $this->database->fetchAssociative($query, [
            'pending' => SuspiciousTimeCaseStatus::Pending->value,
            'marked' => SuspiciousTimeCaseStatus::Marked->value,
            'trusted' => SuspiciousTimeCaseStatus::Trusted->value,
            'fast' => SuspicionDirection::Fast->value,
            'slow' => SuspicionDirection::Slow->value,
            'saysCorrect' => SuspiciousTimeResponse::SaysCorrect->value,
            'now' => $this->now(),
        ]);

        return new SuspiciousTimeQueueCounts(
            fast: (int) $row['fast'],
            slow: (int) $row['slow'],
            replied: (int) $row['replied'],
            marked: (int) $row['marked'],
            trusted: (int) $row['trusted'],
            decisions: (int) $row['decisions'],
        );
    }

    /**
     * The number next to "Time verification" in the key menu: one count over the (status, direction) index. Unlike
     * the tabs it does not leave out times on secret puzzles - it runs on every page an admin or moderator opens.
     */
    public function countPending(): int
    {
        $count = $this->database->fetchOne(
            'SELECT COUNT(*) FROM suspicious_time_case WHERE status = :pending',
            ['pending' => SuspiciousTimeCaseStatus::Pending->value],
        );
        assert(is_int($count));

        return $count;
    }

    /**
     * Puzzle cards of a direction, most players first.
     *
     * @return list<SuspiciousTimePuzzleCard>
     */
    public function puzzleCards(SuspicionDirection $direction): array
    {
        $query = $this->pendingWithCardsSql($direction) . <<<SQL
SELECT
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.pieces_count,
    p.image,
    m.name AS manufacturer_name,
    pd.difficulty_score,
    CASE WHEN conf.pieces_count = p.pieces_count THEN conf.slow_threshold END AS slow_threshold,
    COUNT(DISTINCT pe.player_id) AS players,
    COALESCE(ps.solved_times_solo_count, 0) AS solo_results,
    ps.median_time_solo,
    ps.fastest_time_solo,
    json_agg(json_build_object(
        'case_id', pe.id,
        'time_id', pe.time_id,
        'player_id', pl.id,
        'player_name', pl.name,
        'player_code', pl.code,
        'player_private', pl.is_private,
        'seconds', pe.seconds_to_solve,
        'puzzlers', pe.puzzlers_count,
        'expected_seconds', pe.expected_seconds,
        'expected_source', pe.expected_source,
        'tier', pe.tier,
        'reasons', pe.reasons
    ) ORDER BY pe.tier = :strong DESC, pe.score DESC NULLS LAST, pe.id) AS cases
FROM pending pe
JOIN card_puzzle cp ON cp.puzzle_id = pe.puzzle_id
JOIN puzzle p ON p.id = pe.puzzle_id
JOIN player pl ON pl.id = pe.player_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = p.id
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = p.id
LEFT JOIN suspicious_time_puzzle_confirmation conf ON conf.puzzle_id = p.id
GROUP BY p.id, m.name, pd.difficulty_score, conf.pieces_count, conf.slow_threshold, ps.solved_times_solo_count, ps.median_time_solo, ps.fastest_time_solo
ORDER BY COUNT(DISTINCT pe.player_id) DESC, p.name, p.id
SQL;

        /** @var list<array{puzzle_id: string, puzzle_name: string, pieces_count: int, image: null|string, manufacturer_name: null|string, difficulty_score: null|float|string, slow_threshold: null|float|string, players: int|string, solo_results: int|string, median_time_solo: null|int, fastest_time_solo: null|int, cases: string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, $this->pendingParameters($direction));

        return array_map(static function (array $row) use ($direction): SuspiciousTimePuzzleCard {
            /** @var list<array{case_id: string, time_id: string, player_id: string, player_name: null|string, player_code: string, player_private: bool, seconds: null|int, puzzlers: int, expected_seconds: null|int, expected_source: null|string, tier: null|string, reasons: array<mixed>}> $cases */
            $cases = json_decode($row['cases'], true, flags: JSON_THROW_ON_ERROR);

            return new SuspiciousTimePuzzleCard(
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                manufacturerName: $row['manufacturer_name'],
                piecesCount: $row['pieces_count'],
                image: $row['image'],
                difficultyScore: $row['difficulty_score'] !== null ? (float) $row['difficulty_score'] : null,
                slowThreshold: $row['slow_threshold'] !== null ? (float) $row['slow_threshold'] : null,
                playersCount: (int) $row['players'],
                soloResults: (int) $row['solo_results'],
                medianSolo: $row['median_time_solo'],
                fastestSolo: $row['fastest_time_solo'],
                direction: $direction,
                cases: array_map(static fn (array $case): SuspiciousTimePuzzleCardCase => new SuspiciousTimePuzzleCardCase(
                    caseId: $case['case_id'],
                    timeId: $case['time_id'],
                    playerId: $case['player_id'],
                    playerName: $case['player_name'],
                    playerCode: $case['player_code'],
                    playerPrivate: $case['player_private'],
                    seconds: $case['seconds'],
                    puzzlersCount: $case['puzzlers'],
                    expectedSeconds: $case['expected_seconds'],
                    expectedSource: $case['expected_source'] !== null ? ExpectedTimeSource::from($case['expected_source']) : null,
                    tier: $case['tier'] !== null ? SuspiciousTimeTier::from($case['tier']) : null,
                    reasons: SuspiciousTimeReason::listFromArray($case['reasons']),
                ), $cases),
            );
        }, $rows);
    }

    /**
     * The import cards of a direction, the largest first: the player, the import, how many cases, how far off, the
     * first examples (strong first, then the furthest off) - and how the batch's imported solo results stand to the
     * player's typed ones on the same piece counts. Two statements for all cards.
     *
     * @return list<SuspiciousTimeImportCard>
     */
    public function importCards(SuspicionDirection $direction): array
    {
        $factor = $direction === SuspicionDirection::Fast
            ? 'CAST(ap.expected_seconds AS double precision) / NULLIF(ap.seconds_to_solve, 0)'
            : 'CAST(ap.seconds_to_solve AS double precision) / NULLIF(ap.expected_seconds, 0)';
        // As the profile counts them - an unfinished result is not a solve (CountsAsSolve)
        $countsAsSolve = CountsAsSolve::condition('t');

        $query = $this->pendingWithCardsSql($direction) . <<<SQL
SELECT
    batch.id AS batch_id,
    batch.started_at,
    batch.rows_added,
    batch.results_added,
    import.file_kind,
    pl.id AS player_id,
    pl.name AS player_name,
    pl.code AS player_code,
    pl.is_private AS player_private,
    pl.registered_at,
    -- The player's results as the profile counts them (a result waiting for verification is not marked: it counts)
    (SELECT COUNT(*) FROM puzzle_solving_time t WHERE t.player_id = pl.id AND t.suspicious = false AND {$countsAsSolve}) AS results_total,
    (SELECT COUNT(*) FROM puzzle_solving_time t WHERE t.player_id = pl.id AND t.suspicious = false AND {$countsAsSolve} AND t.created_via = 'spreadsheet_import') AS results_imported,
    (
        SELECT COUNT(*)
        FROM suspicious_time_case other
        JOIN data_import_row other_row ON other_row.solving_time_id = other.time_id AND other_row.result_made AND other_row.state = 'added'
        WHERE other_row.batch_id = batch.id AND other.status = :pending AND other.direction <> :direction
    ) AS other_direction,
    COUNT(*) AS cases,
    MIN({$factor}) AS factor_from,
    MAX({$factor}) AS factor_to,
    json_agg(json_build_object(
        'case_id', ap.id,
        'fingerprint', ap.fingerprint,
        'time_id', ap.time_id,
        'puzzle_id', p.id,
        'puzzle_name', p.name,
        'pieces', p.pieces_count,
        'seconds', ap.seconds_to_solve,
        'expected_seconds', ap.expected_seconds,
        'type', ap.puzzling_type
    ) ORDER BY ap.tier = :strong DESC, ap.score DESC NULLS LAST, ap.id) AS cases_json
FROM all_pending ap
JOIN import_card ic ON ic.import_batch_id = ap.import_batch_id
JOIN data_import_batch batch ON batch.id = ap.import_batch_id
JOIN data_import import ON import.id = batch.import_id
JOIN player pl ON pl.id = import.player_id
JOIN puzzle p ON p.id = ap.puzzle_id
GROUP BY batch.id, import.id, pl.id
ORDER BY COUNT(*) DESC, batch.id
SQL;

        /** @var list<array{batch_id: string, started_at: string, rows_added: int, results_added: int, file_kind: string, player_id: string, player_name: null|string, player_code: string, player_private: bool, registered_at: string, results_total: int, results_imported: int, other_direction: int, cases: int, factor_from: null|float|string, factor_to: null|float|string, cases_json: string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, $this->pendingParameters($direction));

        if ($rows === []) {
            return [];
        }

        $paces = $this->importPaces(array_column($rows, 'batch_id'));

        return array_map(static function (array $row) use ($direction, $paces): SuspiciousTimeImportCard {
            /** @var list<array{case_id: string, fingerprint: string, time_id: string, puzzle_id: string, puzzle_name: string, pieces: int, seconds: null|int, expected_seconds: null|int, type: string}> $cases */
            $cases = json_decode($row['cases_json'], true, flags: JSON_THROW_ON_ERROR);

            return new SuspiciousTimeImportCard(
                batchId: $row['batch_id'],
                direction: $direction,
                addedAt: new \DateTimeImmutable($row['started_at']),
                rowsAdded: $row['rows_added'],
                resultsAdded: $row['results_added'],
                fileKind: $row['file_kind'],
                playerId: $row['player_id'],
                playerName: $row['player_name'],
                playerCode: $row['player_code'],
                playerPrivate: $row['player_private'],
                registeredAt: new \DateTimeImmutable($row['registered_at']),
                resultsTotal: $row['results_total'],
                resultsImported: $row['results_imported'],
                cases: $row['cases'],
                otherDirectionCases: $row['other_direction'],
                factorFrom: $row['factor_from'] === null ? null : (float) $row['factor_from'],
                factorTo: $row['factor_to'] === null ? null : (float) $row['factor_to'],
                examples: array_slice($cases, 0, self::IMPORT_CARD_EXAMPLES),
                paces: $paces[$row['batch_id']] ?? [],
                seen: implode(' ', array_map(static fn (array $case): string => SuspiciousTimeImportCard::seenMark($case['case_id'], $case['fingerprint']), $cases)),
            );
        }, $rows);
    }

    /**
     * The pending cases of one import card, as a group decision takes them: the case and the entry it was raised
     * for (its fingerprint) - a bounded chunk, in the card's order.
     *
     * @return list<array{caseId: string, fingerprint: string}>
     */
    public function importCardCases(string $batchId, SuspicionDirection $direction, int $limit): array
    {
        if (Uuid::isValid($batchId) === false) {
            return [];
        }

        $notSecret = PuzzleSecrecy::sqlNotSecret('p');

        /** @var list<array{id: string, fingerprint: string}> $rows */
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT c.id, c.fingerprint
FROM suspicious_time_case c
JOIN puzzle_solving_time pst ON pst.id = c.time_id AND pst.created_via = 'spreadsheet_import'
JOIN puzzle p ON p.id = pst.puzzle_id
JOIN data_import_row import_row ON import_row.solving_time_id = pst.id AND import_row.result_made AND import_row.state = 'added'
WHERE import_row.batch_id = :batchId
    AND c.status = :pending
    AND c.direction = :direction
    AND {$notSecret}
ORDER BY c.tier = :strong DESC, c.score DESC NULLS LAST, c.id
LIMIT :limit
SQL, [
            'batchId' => $batchId,
            'pending' => SuspiciousTimeCaseStatus::Pending->value,
            'direction' => $direction->value,
            'strong' => SuspiciousTimeTier::Strong->value,
            'now' => $this->now(),
            'limit' => $limit,
        ]);

        return array_map(static fn (array $row): array => ['caseId' => $row['id'], 'fingerprint' => $row['fingerprint']], $rows);
    }

    /**
     * What a moderator needs to judge a history import: per piece count, the pace (pieces a minute, the median) of
     * the solo results the batch added beside the pace of the player's solo results that were **not** imported -
     * typed, timed with the stopwatch, sent by an app. Marked results are left out of both (suspicious = false):
     * a time set aside is no evidence.
     *
     * @param list<string> $batchIds
     *
     * @return array<string, list<array{pieces: int, imported: int, importedPpm: float, typed: int, typedPpm: null|float}>> by batch id
     */
    private function importPaces(array $batchIds): array
    {
        /** @var list<array{batch_id: string, pieces_count: int, imported: int, imported_ppm: null|float|string, typed: int, typed_ppm: null|float|string}> $rows */
        $rows = $this->database->fetchAllAssociative(<<<SQL
WITH batches AS (
    SELECT batch.id AS batch_id, import.player_id
    FROM data_import_batch batch
    JOIN data_import import ON import.id = batch.import_id
    WHERE batch.id IN (:batchIds)
),
paces AS (
    SELECT b.batch_id, p.pieces_count, TRUE AS imported, p.pieces_count * 60.0 / t.seconds_to_solve AS ppm
    FROM batches b
    JOIN data_import_row import_row ON import_row.batch_id = b.batch_id AND import_row.result_made AND import_row.state = 'added'
    JOIN puzzle_solving_time t ON t.id = import_row.solving_time_id
    JOIN puzzle p ON p.id = t.puzzle_id
    WHERE t.puzzling_type = 'solo' AND t.seconds_to_solve > 0 AND t.suspicious = false AND t.player_id = b.player_id

    UNION ALL

    SELECT b.batch_id, p.pieces_count, FALSE, p.pieces_count * 60.0 / t.seconds_to_solve
    FROM batches b
    JOIN puzzle_solving_time t ON t.player_id = b.player_id
    JOIN puzzle p ON p.id = t.puzzle_id
    WHERE t.puzzling_type = 'solo' AND t.seconds_to_solve > 0 AND t.suspicious = false
        AND t.created_via IS DISTINCT FROM 'spreadsheet_import'
)
SELECT
    paces.batch_id,
    paces.pieces_count,
    COUNT(*) FILTER (WHERE paces.imported) AS imported,
    percentile_cont(0.5) WITHIN GROUP (ORDER BY paces.ppm) FILTER (WHERE paces.imported) AS imported_ppm,
    COUNT(*) FILTER (WHERE paces.imported = FALSE) AS typed,
    percentile_cont(0.5) WITHIN GROUP (ORDER BY paces.ppm) FILTER (WHERE paces.imported = FALSE) AS typed_ppm
FROM paces
GROUP BY paces.batch_id, paces.pieces_count
HAVING COUNT(*) FILTER (WHERE paces.imported) > 0
ORDER BY paces.batch_id, COUNT(*) FILTER (WHERE paces.imported) DESC, paces.pieces_count
SQL, ['batchIds' => $batchIds], ['batchIds' => ArrayParameterType::STRING]);

        $paces = [];

        foreach ($rows as $row) {
            $paces[$row['batch_id']][] = [
                'pieces' => $row['pieces_count'],
                'imported' => $row['imported'],
                'importedPpm' => (float) $row['imported_ppm'],
                'typed' => $row['typed'],
                'typedPpm' => $row['typed_ppm'] === null ? null : (float) $row['typed_ppm'],
            ];
        }

        return $paces;
    }

    /**
     * The pending cases of a direction outside the puzzle cards, one page in queue order.
     */
    public function pending(SuspicionDirection $direction, int $page, null|string $onlyImportBatchId = null): SuspiciousTimeQueuePage
    {
        $onlyImportBatchId = $onlyImportBatchId !== null && Uuid::isValid($onlyImportBatchId) ? strtolower($onlyImportBatchId) : null;
        // One import's cases, opened one by one: every one of them, also those a puzzle card would hold
        $outsidePuzzleCards = $onlyImportBatchId !== null ? 'TRUE' : 'pe.puzzle_id NOT IN (SELECT puzzle_id FROM card_puzzle)';
        $order = $direction === SuspicionDirection::Fast
            // Leads its puzzle (the statistics count the pending time itself), then bigger leaderboards first
            ? '(pe.seconds_to_solve <= ps.fastest_time_solo) DESC NULLS LAST, ps.solved_times_solo_count DESC NULLS LAST, pe.score DESC NULLS LAST'
            : 'pe.score DESC NULLS LAST';

        $query = $this->pendingWithCardsSql($direction, $onlyImportBatchId) . <<<SQL
SELECT pe.id, COUNT(*) OVER () AS total
FROM pending pe
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = pe.puzzle_id
WHERE {$outsidePuzzleCards}
ORDER BY pe.tier = :strong DESC, {$order}, pe.id
LIMIT :limit OFFSET :offset
SQL;

        /** @var list<array{id: string, total: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            ...$this->pendingParameters($direction, $onlyImportBatchId),
            'limit' => self::PER_PAGE,
            'offset' => self::offset($page),
        ]);

        return new SuspiciousTimeQueuePage(
            caseIds: array_column($rows, 'id'),
            total: $rows !== [] ? (int) $rows[0]['total'] : 0,
        );
    }

    /**
     * One page of "Player replied" (oldest waiting first), "Needs verification" or "Verified fine" (newest decision
     * first). The tab counts are the totals.
     *
     * @return list<string>
     */
    public function decidedCaseIds(SuspiciousTimeQueueTab $tab, int $page): array
    {
        [$condition, $order] = match ($tab) {
            SuspiciousTimeQueueTab::Replied => [
                'c.status = :marked AND (c.player_edited_at IS NOT NULL OR reply.replied_at IS NOT NULL)',
                'LEAST(c.player_edited_at, reply.replied_at) ASC, c.id',
            ],
            SuspiciousTimeQueueTab::Marked => ['c.status = :marked', 'c.marked_at DESC NULLS LAST, c.id DESC'],
            SuspiciousTimeQueueTab::Trusted => ['c.status = :trusted', 'c.decided_at DESC NULLS LAST, c.id DESC'],
            default => throw new \LogicException(sprintf('Tab "%s" lists no decided cases.', $tab->value)),
        };

        $notSecret = PuzzleSecrecy::sqlNotSecret('p');
        $query = <<<SQL
SELECT c.id
FROM suspicious_time_case c
JOIN puzzle_solving_time pst ON pst.id = c.time_id
JOIN puzzle p ON p.id = pst.puzzle_id
LEFT JOIN LATERAL (
    SELECT MIN(n.responded_at) AS replied_at
    FROM suspicious_time_notice n
    WHERE n.case_id = c.id AND n.marked_at = c.marked_at AND n.response = :saysCorrect AND n.answer IS NULL
) reply ON c.status = :marked
WHERE {$condition} AND {$notSecret}
ORDER BY {$order}
LIMIT :limit OFFSET :offset
SQL;

        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn($query, [
            'marked' => SuspiciousTimeCaseStatus::Marked->value,
            'trusted' => SuspiciousTimeCaseStatus::Trusted->value,
            'saysCorrect' => SuspiciousTimeResponse::SaysCorrect->value,
            'now' => $this->now(),
            'limit' => self::PER_PAGE,
            'offset' => self::offset($page),
        ]);

        return $ids;
    }

    /**
     * The CTEs `pending` (the direction's pending cases on puzzles that are not secret) and `card_puzzle` (the
     * puzzles making a card), followed by the caller's SELECT. The comparable results come from puzzle_statistics
     * (counting them in puzzle_solving_time cost ~8 ms more on the production copy - popular puzzles have thousands).
     */
    private function pendingWithCardsSql(SuspicionDirection $direction, null|string $onlyImportBatchId = null): string
    {
        $notSecret = PuzzleSecrecy::sqlNotSecret('p');
        $farOff = $direction === SuspicionDirection::Fast
            ? 'candidate.difficulty_score < :easyBelow'
            : 'candidate.difficulty_score > :hardAbove';

        // An import card's cases are in the card - unless the moderator opened that very import one by one
        $outsideImportCards = $onlyImportBatchId !== null
            ? 'ap.import_batch_id = CAST(:onlyImportBatch AS UUID)'
            : '(ap.import_batch_id IS NULL OR ap.import_batch_id NOT IN (SELECT ic.import_batch_id FROM import_card ic))';

        return <<<SQL
WITH all_pending AS (
    SELECT
        c.id, c.tier, c.score, c.reasons, c.expected_seconds, c.expected_source, c.fingerprint,
        pst.id AS time_id, pst.puzzle_id, pst.player_id, pst.seconds_to_solve, pst.puzzlers_count, pst.puzzling_type,
        -- The "Add" of a spreadsheet import that made the result (docs/features/suspicious-time-review.md "Import
        -- cards"): the import's row names the result it made - a typed result is no row's, so it is never here
        import_row.batch_id AS import_batch_id
    FROM suspicious_time_case c
    JOIN puzzle_solving_time pst ON pst.id = c.time_id
    JOIN puzzle p ON p.id = pst.puzzle_id
    LEFT JOIN data_import_row import_row
        ON import_row.solving_time_id = pst.id
        AND import_row.result_made
        AND import_row.state = 'added'
        AND pst.created_via = 'spreadsheet_import'
    WHERE c.status = :pending AND c.direction = :direction AND {$notSecret}
),
import_card AS (
    SELECT ap.import_batch_id
    FROM all_pending ap
    WHERE ap.import_batch_id IS NOT NULL
    GROUP BY ap.import_batch_id
    HAVING COUNT(*) >= :importCardMin
),
pending AS (
    SELECT ap.*
    FROM all_pending ap
    WHERE {$outsideImportCards}
),
card_candidate AS (
    SELECT
        pe.puzzle_id,
        pd.difficulty_score,
        COUNT(DISTINCT pe.player_id) AS players,
        COUNT(*) AS raised,
        array_agg(DISTINCT pe.puzzling_type) AS puzzling_types
    FROM pending pe
    LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = pe.puzzle_id
    GROUP BY pe.puzzle_id, pd.difficulty_score
),
card_puzzle AS (
    SELECT candidate.puzzle_id
    FROM card_candidate candidate
    WHERE {$farOff}
        OR (
            candidate.players >= :cardMinPlayers
            AND candidate.raised >= CAST(:cardMinShare AS double precision) * COALESCE((
                SELECT
                    CASE WHEN 'solo' = ANY(candidate.puzzling_types) THEN ps.solved_times_solo_count ELSE 0 END
                    + CASE WHEN 'duo' = ANY(candidate.puzzling_types) THEN ps.solved_times_duo_count ELSE 0 END
                    + CASE WHEN 'team' = ANY(candidate.puzzling_types) THEN ps.solved_times_team_count ELSE 0 END
                FROM puzzle_statistics ps
                WHERE ps.puzzle_id = candidate.puzzle_id
            ), 0)
        )
)

SQL;
    }

    /**
     * @return array<string, int|float|string>
     */
    private function pendingParameters(SuspicionDirection $direction, null|string $onlyImportBatchId = null): array
    {
        $parameters = [
            'importCardMin' => self::IMPORT_CARD_MIN,
            ...($onlyImportBatchId !== null ? ['onlyImportBatch' => $onlyImportBatchId] : []),
            'pending' => SuspiciousTimeCaseStatus::Pending->value,
            'direction' => $direction->value,
            'strong' => SuspiciousTimeTier::Strong->value,
            'cardMinPlayers' => self::CARD_MIN_PLAYERS,
            'cardMinShare' => self::CARD_MIN_SHARE,
            'now' => $this->now(),
        ];

        if ($direction === SuspicionDirection::Fast) {
            $parameters['easyBelow'] = self::CARD_EASY_BELOW;
        } else {
            $parameters['hardAbove'] = self::CARD_HARD_ABOVE;
        }

        return $parameters;
    }

    private static function offset(int $page): int
    {
        return (max(1, $page) - 1) * self::PER_PAGE;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
