<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class CountLoggedPuzzles
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Solving entries the player logged themselves, counted no further than `$upTo` - for "has at least
     * N" questions, which must not get slower for somebody with thousands of them.
     */
    public function ofPlayer(string $playerId, int $upTo): int
    {
        $count = $this->database
            ->executeQuery(
                'SELECT COUNT(*) FROM (SELECT 1 FROM puzzle_solving_time WHERE player_id = :playerId LIMIT :upTo) AS logged_puzzles',
                ['playerId' => $playerId, 'upTo' => $upTo],
                ['upTo' => \Doctrine\DBAL\ParameterType::INTEGER],
            )
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }
}
