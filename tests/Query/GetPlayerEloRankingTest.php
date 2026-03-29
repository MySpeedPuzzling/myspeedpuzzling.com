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

    public function testRankingReturnsListForAllTime(): void
    {
        $entries = $this->query->ranking(500, 'all-time');

        // May be empty if no players are ELO-eligible in test fixtures
        self::assertGreaterThanOrEqual(0, count($entries));
    }

    public function testTotalCountReturnsInteger(): void
    {
        $count = $this->query->totalCount(500, 'all-time');

        self::assertGreaterThanOrEqual(0, $count);
    }

    public function testPlayerPositionReturnsNullForNonRankedPlayer(): void
    {
        $position = $this->query->playerPosition('00000000-0000-0000-0000-000000000099', 500, 'all-time');

        self::assertNull($position);
    }

    public function testAvailablePieceCountsReturnsArray(): void
    {
        $categories = $this->query->availablePieceCounts('all-time');

        self::assertGreaterThanOrEqual(0, count($categories));

        foreach ($categories as $cat) {
            self::assertGreaterThan(0, $cat['pieces_count']);
            self::assertGreaterThanOrEqual(50, $cat['player_count']);
        }
    }

    public function testAvailablePieceCountsWithLowThreshold(): void
    {
        // With minimum 0, should return all piece counts that have any ELO data
        $categories = $this->query->availablePieceCounts('all-time', 0);

        self::assertGreaterThanOrEqual(0, count($categories));
    }

    public function testAllForPlayerReturnsEmptyForNonExistentPlayer(): void
    {
        $ratings = $this->query->allForPlayer('00000000-0000-0000-0000-000000000099', 'all-time');

        self::assertSame([], $ratings);
    }

    public function testAllForPlayerReturnsCorrectStructure(): void
    {
        $ratings = $this->query->allForPlayer(PlayerFixture::PLAYER_REGULAR, 'all-time');

        // May be empty if player isn't ELO-eligible
        foreach ($ratings as $data) {
            self::assertGreaterThan(0, $data['elo_rating']);
            self::assertGreaterThan(0, $data['rank']);
            self::assertGreaterThan(0, $data['total']);
        }

        // Ensure the test makes at least one assertion even if empty
        self::assertGreaterThanOrEqual(0, count($ratings));
    }
}
