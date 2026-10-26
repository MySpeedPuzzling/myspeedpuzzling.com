<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetInsightsContext;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\InsightsProfileAxis;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetInsightsContextTest extends KernelTestCase
{
    public function testAPlayerWithoutBatchDataGetsAnEmptyContext(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('DELETE FROM insights_generation');
        $connection->executeStatement('DELETE FROM player_insights_profile');

        $context = self::getContainer()->get(GetInsightsContext::class)->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertSame(0, $context->generation);
        self::assertSame([], $context->profileAxes);
        self::assertNull($context->profileComputedAt);
    }

    public function testTheBatchDataOfThePlayer(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('DELETE FROM player_insights_profile');
        $connection->executeStatement("INSERT INTO insights_generation (id, generation, advanced_at) VALUES (1, 41, " . TestClock::SQL_NOW . ") ON CONFLICT (id) DO UPDATE SET generation = 41");
        $connection->executeStatement(
            'INSERT INTO player_insights_profile (player_id, speed_score, speed_sample_size, speed_percentile, computed_at) VALUES (:player, 0.91, 17, 64.5, ' . TestClock::SQL_NOW . ')',
            ['player' => PlayerFixture::PLAYER_REGULAR],
        );

        $context = self::getContainer()->get(GetInsightsContext::class)->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertSame(41, $context->generation);
        self::assertCount(count(InsightsProfileAxis::cases()), $context->profileAxes);
        self::assertSame(InsightsProfileAxis::Speed, $context->profileAxes[0]->axis);
        self::assertSame(0.91, $context->profileAxes[0]->score);
        self::assertSame(17, $context->profileAxes[0]->sampleSize);
        self::assertSame(64.5, $context->profileAxes[0]->percentile);
        self::assertNull($context->profileAxes[1]->percentile);
        self::assertNotNull($context->profileComputedAt);

        // Another player's card is never read
        self::assertSame([], self::getContainer()->get(GetInsightsContext::class)->forPlayer(PlayerFixture::PLAYER_ADMIN)->profileAxes);
    }
}
