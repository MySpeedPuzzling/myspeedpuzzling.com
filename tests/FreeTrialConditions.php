<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;

/**
 * Fixture players are all registered "now" and have plenty of puzzles logged - exactly half of what the
 * free trial asks for. These put a player where a test needs them.
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

    /**
     * Hands the rest of the player's logged puzzles to somebody else - deleting them would drag half of
     * the fixtures along.
     */
    private function keepLoggedPuzzles(Connection $database, string $playerId, int $keep): void
    {
        $database->executeStatement(
            'UPDATE puzzle_solving_time SET player_id = :other WHERE id IN (
                SELECT id FROM puzzle_solving_time WHERE player_id = :id ORDER BY id OFFSET :keep
            )',
            ['other' => PlayerFixture::PLAYER_ADMIN, 'id' => $playerId, 'keep' => $keep],
            ['keep' => \Doctrine\DBAL\ParameterType::INTEGER],
        );
    }
}
