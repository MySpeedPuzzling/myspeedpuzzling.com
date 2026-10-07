<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeClassifier;
use SpeedPuzzling\Web\Value\PaceReferences;
use SpeedPuzzling\Web\Value\PaceRequest;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * The pace fallback of the suspicious time scan (docs/features/suspicious-time-review.md, "The expected time"): for a
 * time without a prediction or a baseline, the player's other non-suspicious solo results within ±180 days of it -
 * each result's pieces per minute ÷ the community solo median of its piece-count range - and the median of those, from
 * at least SuspiciousTimeClassifier::PACE_MIN_RESULTS results. Results of ranges without a community reference do not
 * count (nothing to compare them by).
 *
 * One statement per call for every player asked about (the scan asks for one batch of players at a time); the window
 * medians are computed here.
 */
readonly final class GetPlayerPaces
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<PaceRequest> $requests
     * @return array<string, float> the relative pace keyed by request key - only where there were enough results
     */
    public function forTimes(array $requests, PaceReferences $references): array
    {
        if ($requests === [] || $references->count() === 0) {
            return [];
        }

        $playerIds = array_values(array_unique(array_map(static fn (PaceRequest $request): string => $request->playerId, $requests)));

        $query = <<<SQL
SELECT
    pst.player_id,
    pst.id,
    p.pieces_count,
    pst.seconds_to_solve,
    EXTRACT(EPOCH FROM COALESCE(pst.finished_at, pst.tracked_at))::bigint AS solved_at
FROM puzzle_solving_time pst
INNER JOIN puzzle p ON p.id = pst.puzzle_id
WHERE pst.player_id IN (:playerIds)
    AND pst.puzzling_type = 'solo'
    AND pst.suspicious = false
    AND pst.seconds_to_solve > 0
SQL;

        /** @var list<array{player_id: string, id: string, pieces_count: int|string, seconds_to_solve: int|string, solved_at: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            $query,
            ['playerIds' => $playerIds],
            ['playerIds' => ArrayParameterType::STRING],
        );

        /** @var array<string, list<array{int, string, float}>> $resultsByPlayer [solved at, time id, relative pace] */
        $resultsByPlayer = [];

        foreach ($rows as $row) {
            $piecesCount = (int) $row['pieces_count'];
            $reference = $references->for($piecesCount, PuzzlingType::Solo);

            if ($reference === null) {
                continue;
            }

            $resultsByPlayer[$row['player_id']][] = [(int) $row['solved_at'], $row['id'], $reference->relativePace($piecesCount, (int) $row['seconds_to_solve'])];
        }

        $times = [];

        foreach ($resultsByPlayer as $playerId => $results) {
            usort($results, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $resultsByPlayer[$playerId] = $results;
            $times[$playerId] = array_column($results, 0);
        }

        $window = SuspiciousTimeClassifier::PACE_WINDOW_DAYS * 86400;
        $paces = [];

        foreach ($requests as $request) {
            $results = $resultsByPlayer[$request->playerId] ?? [];

            if (count($results) < SuspiciousTimeClassifier::PACE_MIN_RESULTS) {
                continue;
            }

            $from = self::firstAtOrAfter($times[$request->playerId], $request->solvedAt - $window);
            $values = [];

            for ($index = $from, $count = count($results); $index < $count && $results[$index][0] <= $request->solvedAt + $window; $index++) {
                if ($results[$index][1] !== $request->excludeTimeId) {
                    $values[] = $results[$index][2];
                }
            }

            if (count($values) >= SuspiciousTimeClassifier::PACE_MIN_RESULTS) {
                $paces[$request->key] = SuspiciousTimeClassifier::median($values);
            }
        }

        return $paces;
    }

    /**
     * @param list<int> $sortedTimes
     */
    private static function firstAtOrAfter(array $sortedTimes, int $moment): int
    {
        $low = 0;
        $high = count($sortedTimes);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($sortedTimes[$middle] < $moment) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }
}
