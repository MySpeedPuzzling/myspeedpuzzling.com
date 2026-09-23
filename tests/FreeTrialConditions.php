<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;

/**
 * Fixture players are all registered "now" - too young for the free trial. This puts a player where a
 * test needs them.
 */
trait FreeTrialConditions
{
    private function registeredDaysAgo(Connection $database, string $playerId, int $days, int $hours = 0): void
    {
        $database->executeStatement(
            'UPDATE player SET registered_at = NOW() - make_interval(days => :days, hours => :hours) WHERE id = :id',
            ['days' => $days, 'hours' => $hours, 'id' => $playerId],
        );
    }
}
