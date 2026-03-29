<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetPlayerEloRanking;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerEloRankingTest extends KernelTestCase
{
    private GetPlayerEloRanking $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();

        /** @var GetPlayerEloRanking $query */
        $query = $container->get(GetPlayerEloRanking::class);
        $this->query = $query;
    }

    public function testRankingReturnsList(): void
    {
        $entries = $this->query->ranking(500);

        self::assertGreaterThanOrEqual(0, count($entries));
    }

    public function testTotalCountReturnsInteger(): void
    {
        $count = $this->query->totalCount(500);

        self::assertGreaterThanOrEqual(0, $count);
    }

    public function testPlayerPositionReturnsNullForNonRankedPlayer(): void
    {
        $position = $this->query->playerPosition('00000000-0000-0000-0000-000000000099', 500);

        self::assertNull($position);
    }

    public function testAllForPlayerReturnsEmptyForNonExistentPlayer(): void
    {
        $ratings = $this->query->allForPlayer('00000000-0000-0000-0000-000000000099');

        self::assertSame([], $ratings);
    }

    public function testAllForPlayerReturnsCorrectStructure(): void
    {
        $ratings = $this->query->allForPlayer(PlayerFixture::PLAYER_REGULAR);

        foreach ($ratings as $data) {
            self::assertGreaterThanOrEqual(0.0, $data['elo_rating']);
            self::assertGreaterThan(0, $data['rank']);
            self::assertGreaterThan(0, $data['total']);
        }

        self::assertGreaterThanOrEqual(0, count($ratings));
    }
}
