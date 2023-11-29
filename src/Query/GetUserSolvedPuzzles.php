<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetUserSolvedPuzzles
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<string>
     */
    public function byUserId(null|string $userId): array
    {
        if ($userId === null) {
            return [];
        }

        $query = <<<SQL
SELECT
    puzzle_solving_time.puzzle_id AS puzzle_id
FROM player
LEFT JOIN puzzle_solving_time ON puzzle_solving_time.player_id = player.id
WHERE player.user_id = :userId
GROUP BY puzzle_solving_time.puzzle_id
SQL;

        /** @var list<string> $puzzleIds */
        $puzzleIds = $this->database
            ->executeQuery($query, [
                'userId' => $userId,
            ])
            ->fetchFirstColumn();

        return $puzzleIds;
    }
}
