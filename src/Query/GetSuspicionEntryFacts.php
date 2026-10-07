<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\SolveMoment;
use SpeedPuzzling\Web\Value\SuspicionDirection;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;

/**
 * Facts of one entry for SingleTimeSuspicionCheck. forEntry(): the puzzle's piece count, the player's baseline for it
 * and the puzzle's difficulty (when its confidence is not insufficient) in one statement - the baseline step; it reads
 * no results at all, so suspicious ones play no part. earlierAttemptRaisedSlow(): the scan's "the prediction was built
 * on a far too slow attempt" fact (GetSuspiciousTimeCandidates, previous_attempt_raised_slow) for one entry.
 */
readonly final class GetSuspicionEntryFacts
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return null|array{pieces_count: int, baseline_seconds: null|int, difficulty_score: null|float} null for an unknown puzzle
     */
    public function forEntry(string $playerId, string $puzzleId): null|array
    {
        $query = <<<SQL
SELECT
    p.pieces_count,
    (SELECT pb.baseline_seconds FROM player_baseline pb WHERE pb.player_id = :playerId AND pb.pieces_count = p.pieces_count) AS baseline_seconds,
    (SELECT pd.difficulty_score FROM puzzle_difficulty pd WHERE pd.puzzle_id = p.id AND pd.confidence <> 'insufficient') AS difficulty_score
FROM puzzle p
WHERE p.id = :puzzleId
SQL;

        /** @var false|array{pieces_count: int|string, baseline_seconds: null|int|string, difficulty_score: null|float|string} $row */
        $row = $this->database->fetchAssociative($query, ['playerId' => $playerId, 'puzzleId' => $puzzleId]);

        if ($row === false) {
            return null;
        }

        return [
            'pieces_count' => (int) $row['pieces_count'],
            'baseline_seconds' => $row['baseline_seconds'] === null ? null : (int) $row['baseline_seconds'],
            'difficulty_score' => $row['difficulty_score'] === null ? null : (float) $row['difficulty_score'],
        ];
    }

    /**
     * Whether an earlier solo attempt of the puzzle by the player (before the entry's moment, the edited time itself
     * left out) has a pending or marked slow case - like the scan's previous_attempt_raised_slow.
     */
    public function earlierAttemptRaisedSlow(string $playerId, string $puzzleId, null|string $excludeTimeId, SolveMoment $moment): bool
    {
        $excludeFilter = $excludeTimeId !== null ? 'AND earlier.id <> :excludeTimeId' : '';

        $query = <<<SQL
SELECT EXISTS (
    SELECT 1
    FROM puzzle_solving_time earlier
    INNER JOIN suspicious_time_case earlier_case ON earlier_case.time_id = earlier.id
    WHERE earlier.player_id = :playerId
        AND earlier.puzzle_id = :puzzleId
        AND earlier.puzzling_type = 'solo'
        {$excludeFilter}
        AND (COALESCE(earlier.finished_at, earlier.tracked_at), earlier.tracked_at) < (CAST(:solvedAt AS timestamp), CAST(:trackedAt AS timestamp))
        AND earlier_case.direction = :slow
        AND earlier_case.status IN (:pending, :marked)
)
SQL;

        $params = [
            'playerId' => $playerId,
            'puzzleId' => $puzzleId,
            'solvedAt' => $moment->solvedAt->format('Y-m-d H:i:s'),
            'trackedAt' => $moment->trackedAt->format('Y-m-d H:i:s'),
            'slow' => SuspicionDirection::Slow->value,
            'pending' => SuspiciousTimeCaseStatus::Pending->value,
            'marked' => SuspiciousTimeCaseStatus::Marked->value,
        ];

        if ($excludeTimeId !== null) {
            $params['excludeTimeId'] = $excludeTimeId;
        }

        return (bool) $this->database->fetchOne($query, $params);
    }
}
