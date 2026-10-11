<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetPlayerRatingRanking;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerRatingRankingTest extends KernelTestCase
{
    private GetPlayerRatingRanking $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();

        /** @var GetPlayerRatingRanking $query */
        $query = $container->get(GetPlayerRatingRanking::class);
        $this->query = $query;
    }

    public function testRankingReturnsList(): void
    {
        $entries = $this->query->ranking(500);

        self::assertGreaterThanOrEqual(0, count($entries));

        foreach ($entries as $entry) {
            if ($entry->skillTierName !== null) {
                self::assertContains($entry->skillTierName, ['enthusiast', 'apprentice', 'proficient', 'advanced', 'expert', 'master', 'legend']);
            }
        }
    }

    public function testTotalCountReturnsInteger(): void
    {
        $count = $this->query->window(500, 100)->total;

        self::assertGreaterThanOrEqual(0, $count);
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

    public function testRankingWithSearchFilter(): void
    {
        $allEntries = $this->query->ranking(500);

        if (count($allEntries) === 0) {
            self::markTestSkipped('No rating entries in fixtures');
        }

        $entry = $allEntries[0];
        $searchTerm = $entry->playerName ?? $entry->playerCode;

        $filtered = $this->query->ranking(500, 50, searchTerm: $searchTerm);

        self::assertGreaterThanOrEqual(1, count($filtered));

        $found = false;

        foreach ($filtered as $filteredEntry) {
            if ($filteredEntry->playerId === $entry->playerId) {
                $found = true;
                self::assertSame($entry->rank, $filteredEntry->rank);
            }
        }

        self::assertTrue($found);
    }

    public function testTotalCountWithFilters(): void
    {
        $totalAll = $this->query->window(500, 100)->total;
        $totalNonExistentCountry = $this->query->window(500, 100, country: 'xx')->total;

        self::assertGreaterThanOrEqual(0, $totalAll);
        self::assertSame(0, $totalNonExistentCountry);
    }

    public function testDistinctCountries(): void
    {
        $countries = $this->query->distinctCountries(500);

        self::assertGreaterThanOrEqual(0, count($countries));

        foreach ($countries as $code) {
            self::assertNotEmpty($code);
        }
    }
}
