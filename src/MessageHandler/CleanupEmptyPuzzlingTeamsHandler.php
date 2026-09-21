<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\CleanupEmptyPuzzlingTeams;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A team never changes members - editing who took part in a time moves the time to another team, and the
 * old one may be left with nothing. Such leftovers are invisible (lists show a team only when it has
 * results, a name, or was prepared on purpose); this removes them. Anything with a result, a name or a
 * preparer is never touched.
 */
#[AsMessageHandler]
readonly final class CleanupEmptyPuzzlingTeamsHandler
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int how many teams were removed
     */
    public function __invoke(CleanupEmptyPuzzlingTeams $message): int
    {
        return (int) $this->connection->executeStatement(
            <<<SQL
DELETE FROM puzzling_team team
WHERE team.name IS NULL
    AND team.prepared_by_id IS NULL
    -- never a team somebody is creating right now
    AND team.created_at < :before
    AND NOT EXISTS (SELECT 1 FROM puzzle_solving_time time WHERE time.puzzling_team_id = team.id)
SQL,
            ['before' => $this->clock->now()->modify('-1 day')->format('Y-m-d H:i:s')],
        );
    }
}
