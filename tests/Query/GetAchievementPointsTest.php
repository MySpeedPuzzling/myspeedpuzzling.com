<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetAchievementPoints;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\BadgeTier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetAchievementPointsTest extends KernelTestCase
{
    public function testSumsPointsAcrossTiersAndGuardsSqlAgainstEnumDrift(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);
        $query = self::getContainer()->get(GetAchievementPoints::class);

        $playerId = PlayerFixture::PLAYER_WITH_FAVORITES;

        self::assertSame(0, $query->forPlayer($playerId));

        $expected = 0;
        foreach (BadgeTier::cases() as $tier) {
            $database->executeStatement(
                "INSERT INTO badge (id, player_id, type, earned_at, tier)
                 VALUES (:id, :playerId, 'puzzles_solved', NOW(), :tier)",
                ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'tier' => $tier->value],
            );
            $expected += $tier->points();
        }

        // Single-tier achievement (tier NULL) grants the flat 25.
        $database->executeStatement(
            "INSERT INTO badge (id, player_id, type, earned_at, tier)
             VALUES (:id, :playerId, 'supporter', NOW(), NULL)",
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId],
        );
        $expected += BadgeTier::SINGLE_TIER_POINTS;

        // Full ladder (5+10+25+50+100) + Early Adopter (25) = 215; the SQL CASE must
        // agree with BadgeTier::points() — this assertion catches drift in either place.
        self::assertSame(215, $expected);
        self::assertSame($expected, $query->forPlayer($playerId));
    }
}
