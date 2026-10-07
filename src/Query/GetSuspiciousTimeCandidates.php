<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Generator;
use SpeedPuzzling\Web\Results\SuspicionCandidate;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeClassifier;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SuspicionCheckOutcome;
use SpeedPuzzling\Web\Value\SuspicionDirection;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;

/**
 * The times the suspicious time scan checks (docs/features/suspicious-time-review.md, "Checks and versions"):
 * without a check, checked by another detector version, a different entry since (fingerprint), or no_data and solved
 * recently enough to be judged once the player has a level - but never a time a person decided about (a marked or
 * trusted case) while its fingerprint is unchanged. Flagged times are not classified - the flag reconciliation
 * handles them. Results with a time only; pair/team results too (judged by the slow floor only).
 *
 * Each solo candidate carries what the classifier needs except the pace: the stored prediction (predictable = true)
 * with the attempt a personal one was built on, the player's baseline for the piece count and the puzzle's difficulty
 * when its confidence is not insufficient. Only for a time far faster than a personal prediction: whether an earlier
 * attempt of the puzzle has a pending or marked slow case (the prediction may be inflated by it) - a lookup for a few
 * hundred rows, CASE evaluates it for no other.
 *
 * Streamed: the first run checks every result with a time (~510k on a copy of production), so the rows come through a
 * server-side cursor in pages and leave in batches of whole players - the pace fallback reads one player's results
 * once per batch.
 */
readonly final class GetSuspiciousTimeCandidates
{
    private const string CURSOR = 'suspicious_time_candidates';
    private const int FETCH_SIZE = 5000;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return Generator<int, list<SuspicionCandidate>> batches of whole players, at least $batchSize times each (but the last)
     */
    public function batches(int $version, DateTimeImmutable $noDataSince, int $batchSize): Generator
    {
        $batch = [];
        $lastPlayerId = null;

        foreach ($this->rows($version, $noDataSince) as $row) {
            $candidate = self::candidate($row);

            if (count($batch) >= $batchSize && $candidate->playerId !== $lastPlayerId) {
                yield $batch;
                $batch = [];
            }

            $batch[] = $candidate;
            $lastPlayerId = $candidate->playerId;
        }

        if ($batch !== []) {
            yield $batch;
        }
    }

    /**
     * Marked cases whose time is another entry now than the one the case is about (docs/features/suspicious-time-review.md,
     * "When the player edits a marked time"): changed without the edit form - a piece count fixed, a merge, an SQL
     * repair, an edit whose re-check failed. The candidates leave flagged times out, so the scan judges these again
     * on their own (MarkedTimeEditRecheck::afterOutsideChange()). Only flagged times - a cleared flag is the
     * reconciliation's.
     *
     * @return list<string> case ids
     */
    public function markedWithChangedEntry(): array
    {
        $fingerprint = SuspicionFingerprint::sql('pst', 'p');

        $query = <<<SQL
SELECT sc.id
FROM suspicious_time_case sc
INNER JOIN puzzle_solving_time pst ON pst.id = sc.time_id
INNER JOIN puzzle p ON p.id = pst.puzzle_id
WHERE sc.status = :marked
    AND pst.suspicious = true
    AND sc.fingerprint <> {$fingerprint}
ORDER BY sc.id
SQL;

        /** @var list<string> $caseIds */
        $caseIds = $this->database->fetchFirstColumn($query, ['marked' => SuspiciousTimeCaseStatus::Marked->value]);

        return $caseIds;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function rows(int $version, DateTimeImmutable $noDataSince): Generator
    {
        $fingerprint = SuspicionFingerprint::sql('pst', 'p');
        $decided = implode(', ', array_map(
            fn (SuspiciousTimeCaseStatus $status): string => $this->database->quote($status->value),
            SuspiciousTimeCaseStatus::decided(),
        ));
        $noData = $this->database->quote(SuspicionCheckOutcome::NoData->value);
        $openStatuses = implode(', ', array_map(
            fn (SuspiciousTimeCaseStatus $status): string => $this->database->quote($status->value),
            [SuspiciousTimeCaseStatus::Pending, SuspiciousTimeCaseStatus::Marked],
        ));
        $slow = $this->database->quote(SuspicionDirection::Slow->value);
        $fastFrom = SuspiciousTimeClassifier::PREDICTION_RAISE_RATIO;
        // Inlined: a cursor declaration takes no bind parameters - an int and a formatted date, nothing a player typed
        $since = $this->database->quote($noDataSince->format('Y-m-d H:i:s'));

        $query = <<<SQL
SELECT
    pst.id AS time_id,
    pst.player_id,
    pst.puzzle_id,
    p.pieces_count,
    pst.seconds_to_solve,
    pst.puzzling_type,
    EXTRACT(EPOCH FROM COALESCE(pst.finished_at, pst.tracked_at))::bigint AS solved_at,
    CASE WHEN pst.puzzling_type = 'solo' AND pst.predictable = true THEN pst.predicted_seconds END AS predicted_seconds,
    CASE WHEN pst.puzzling_type = 'solo' AND pst.predictable = true THEN pst.prediction_last_time_seconds END AS previous_attempt_seconds,
    CASE
        WHEN pst.puzzling_type = 'solo' AND pst.predictable = true AND pst.prediction_last_time_seconds IS NOT NULL
            AND pst.predicted_seconds >= {$fastFrom} * pst.seconds_to_solve
        THEN EXISTS (
            SELECT 1
            FROM puzzle_solving_time earlier
            INNER JOIN suspicious_time_case earlier_case ON earlier_case.time_id = earlier.id
            WHERE earlier.player_id = pst.player_id
                AND earlier.puzzle_id = pst.puzzle_id
                AND earlier.puzzling_type = 'solo'
                AND earlier.id <> pst.id
                AND (COALESCE(earlier.finished_at, earlier.tracked_at), earlier.tracked_at) < (COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at)
                AND earlier_case.direction = {$slow}
                AND earlier_case.status IN ({$openStatuses})
        )
        ELSE false
    END AS previous_attempt_raised_slow,
    CASE WHEN pst.puzzling_type = 'solo' THEN pb.baseline_seconds END AS baseline_seconds,
    CASE WHEN pst.puzzling_type = 'solo' AND pd.confidence <> 'insufficient' THEN pd.difficulty_score END AS difficulty_score,
    {$fingerprint} AS fingerprint,
    sc.id AS case_id,
    sc.status AS case_status
FROM puzzle_solving_time pst
INNER JOIN puzzle p ON p.id = pst.puzzle_id
LEFT JOIN suspicious_time_check c ON c.time_id = pst.id
LEFT JOIN suspicious_time_case sc ON sc.time_id = pst.id
LEFT JOIN player_baseline pb ON pb.player_id = pst.player_id AND pb.pieces_count = p.pieces_count
LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = pst.puzzle_id
WHERE pst.seconds_to_solve > 0
    AND pst.suspicious = false
    AND p.pieces_count > 0
    AND (
        c.time_id IS NULL
        OR c.version <> {$version}
        OR c.fingerprint <> {$fingerprint}
        OR (c.outcome = {$noData} AND COALESCE(pst.finished_at, pst.tracked_at) >= {$since})
    )
    AND (sc.id IS NULL OR sc.status NOT IN ({$decided}) OR sc.fingerprint <> {$fingerprint})
ORDER BY pst.player_id, pst.id
SQL;

        // A cursor lives in a transaction - the scan's handler has one (doctrine_transaction). Anything else reads it at once.
        if ($this->database->isTransactionActive() === false) {
            yield from $this->database->executeQuery($query)->iterateAssociative();

            return;
        }

        // A full pass, planned for the whole result with hash joins (~1.5 s on a copy of production). Left to itself
        // the planner optimises a cursor for its first rows - right after a mass change of the checks, on statistics
        // not yet refreshed, that picked nested loops which ran for many minutes. The plan is fixed at DECLARE, so the
        // settings go back to their defaults for the rest of the transaction right after it.
        $this->database->executeStatement('SET LOCAL cursor_tuple_fraction = 1.0');
        $this->database->executeStatement('SET LOCAL enable_nestloop = off');
        $this->database->executeStatement('DECLARE ' . self::CURSOR . ' NO SCROLL CURSOR FOR ' . $query);
        $this->database->executeStatement('SET LOCAL enable_nestloop TO DEFAULT');
        $this->database->executeStatement('SET LOCAL cursor_tuple_fraction TO DEFAULT');

        try {
            do {
                $rows = $this->database->fetchAllAssociative('FETCH FORWARD ' . self::FETCH_SIZE . ' FROM ' . self::CURSOR);

                yield from $rows;
            } while (count($rows) === self::FETCH_SIZE);
        } finally {
            // The cursor dies with the transaction anyway - closed now so the same transaction can scan again (tests).
            // After a failed statement the transaction is aborted and refuses the CLOSE: nothing left to clean up then.
            try {
                $this->database->executeStatement('CLOSE ' . self::CURSOR);
            } catch (DBALException) {
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function candidate(array $row): SuspicionCandidate
    {
        /** @var array{time_id: string, player_id: string, puzzle_id: string, pieces_count: int|string, seconds_to_solve: int|string, puzzling_type: string, solved_at: int|string, predicted_seconds: null|int|string, previous_attempt_seconds: null|int|string, previous_attempt_raised_slow: bool, baseline_seconds: null|int|string, difficulty_score: null|float|string, fingerprint: string, case_id: null|string, case_status: null|string} $row */

        return new SuspicionCandidate(
            timeId: $row['time_id'],
            playerId: $row['player_id'],
            puzzleId: $row['puzzle_id'],
            piecesCount: (int) $row['pieces_count'],
            seconds: (int) $row['seconds_to_solve'],
            puzzlingType: PuzzlingType::from($row['puzzling_type']),
            solvedAt: (int) $row['solved_at'],
            predictedSeconds: $row['predicted_seconds'] === null ? null : (int) $row['predicted_seconds'],
            previousAttemptSeconds: $row['previous_attempt_seconds'] === null ? null : (int) $row['previous_attempt_seconds'],
            previousAttemptRaisedSlow: $row['previous_attempt_raised_slow'],
            baselineSeconds: $row['baseline_seconds'] === null ? null : (int) $row['baseline_seconds'],
            difficultyScore: $row['difficulty_score'] === null ? null : (float) $row['difficulty_score'],
            fingerprint: $row['fingerprint'],
            caseId: $row['case_id'],
            caseStatus: $row['case_status'] === null ? null : SuspiciousTimeCaseStatus::from($row['case_status']),
        );
    }
}
