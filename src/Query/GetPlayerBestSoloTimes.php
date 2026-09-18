<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * A player's best solo time on a handful of puzzles - the "My time" line of the
 * activity feed. Same value and same puzzles as GetRanking::allForPlayer()'s
 * `time`, without ranking the player against everybody on every puzzle they
 * ever solved (that query costs 100-300 ms for active players, Sentry WEB-BP).
 */
readonly final class GetPlayerBestSoloTimes
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Puzzles without a timed solo solve by the player are absent from the result.
     *
     * @param list<string> $puzzleIds
     *
     * @return array<string, int> seconds keyed by puzzle id
     */
    public function forPuzzles(string $playerId, array $puzzleIds): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        // Joins mirror GetRanking::allForPlayer(), which leaves out puzzles without a manufacturer
        $query = <<<SQL
SELECT pst.puzzle_id, MIN(pst.seconds_to_solve) AS best_time
FROM puzzle_solving_time pst
INNER JOIN puzzle p ON p.id = pst.puzzle_id
INNER JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE pst.player_id = :playerId
    AND pst.puzzling_type = 'solo'
    AND pst.seconds_to_solve IS NOT NULL
    AND pst.puzzle_id IN (:puzzleIds)
GROUP BY pst.puzzle_id
SQL;

        /** @var list<array{puzzle_id: string, best_time: int|string}> $rows */
        $rows = $this->database->executeQuery(
            $query,
            ['playerId' => $playerId, 'puzzleIds' => $puzzleIds],
            ['puzzleIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $bestTimes = [];

        foreach ($rows as $row) {
            $bestTimes[$row['puzzle_id']] = (int) $row['best_time'];
        }

        return $bestTimes;
    }
}
