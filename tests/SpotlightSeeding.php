<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;

/**
 * Precomputed people numbers for the Players page spotlight (docs/features/players-page/README.md), written straight
 * into community_player_stats - the fixtures have five players, too few for ten-row lists, and their numbers depend on
 * the day the test database was built. Plain SQL on purpose; DAMA rolls the rows back with the test.
 *
 * Recalculate first: the recalculation rewrites every player's row from their results.
 */
trait SpotlightSeeding
{
    protected function recalculateCommunityStats(): void
    {
        (self::getContainer()->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());
    }

    /**
     * Everybody's list numbers to zero, so only what a test sets decides who is listed.
     */
    protected function clearSpotlightNumbers(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE community_player_stats SET solves_this_month = 0, pieces_this_month = 0, favorites_count = 0, solved_total = 0',
        );
    }

    /**
     * @param array<string, int> $numbers community_player_stats column => value
     */
    protected function setSpotlightNumbers(string $playerId, array $numbers, null|string $registeredAt = null): void
    {
        $database = self::getContainer()->get(Connection::class);

        foreach ($numbers as $column => $value) {
            assert(preg_match('/^[a-z0-9_]+$/', $column) === 1);

            $database->executeStatement(
                "UPDATE community_player_stats SET {$column} = :value WHERE player_id = :id",
                ['value' => $value, 'id' => $playerId],
                ['value' => ParameterType::INTEGER],
            );
        }

        if ($registeredAt !== null) {
            $database->executeStatement(
                'UPDATE player SET registered_at = :registeredAt WHERE id = :id',
                ['registeredAt' => (new DateTimeImmutable($registeredAt))->format('Y-m-d H:i:s'), 'id' => $playerId],
            );
        }
    }

    /**
     * A new player with their precomputed numbers.
     */
    protected function seedSpotlightPlayer(
        string $name,
        null|string $country = 'cz',
        int $solvesThisMonth = 0,
        int $piecesThisMonth = 0,
        int $favoritesCount = 0,
        int $solvedTotal = 1,
        string $registeredAt = '-1 year',
        bool $private = false,
    ): string {
        $database = self::getContainer()->get(Connection::class);
        $id = Uuid::uuid7()->toString();

        $database->executeStatement(
            'INSERT INTO player (id, code, name, country, is_private, registered_at) VALUES (:id, :code, :name, :country, :private, :registeredAt)',
            [
                'id' => $id,
                'code' => 'spot' . substr(str_replace('-', '', $id), -12),
                'name' => $name,
                'country' => $country,
                'private' => $private,
                'registeredAt' => (new DateTimeImmutable($registeredAt))->format('Y-m-d H:i:s'),
            ],
            ['private' => ParameterType::BOOLEAN],
        );

        $database->executeStatement(
            <<<SQL
INSERT INTO community_player_stats (
    player_id, solved_total, pieces_total, solves7d, pieces7d, solves30d, solves_prev30d,
    solves_this_month, pieces_this_month, solves_last_month, pieces_last_month,
    best500_seconds, best1000_seconds, first_solved_at, last_solved_at, monthly_solves, favorites_count, computed_at
)
VALUES (
    :id, :solvedTotal, 0, 0, 0, 0, 0,
    :solvesThisMonth, :piecesThisMonth, 0, 0,
    NULL, NULL, NULL, NULL, '[0,0,0,0,0,0,0,0,0,0,0,0]', :favoritesCount, NOW()
)
SQL,
            [
                'id' => $id,
                'solvedTotal' => $solvedTotal,
                'solvesThisMonth' => $solvesThisMonth,
                'piecesThisMonth' => $piecesThisMonth,
                'favoritesCount' => $favoritesCount,
            ],
            [
                'solvedTotal' => ParameterType::INTEGER,
                'solvesThisMonth' => ParameterType::INTEGER,
                'piecesThisMonth' => ParameterType::INTEGER,
                'favoritesCount' => ParameterType::INTEGER,
            ],
        );

        return $id;
    }
}
