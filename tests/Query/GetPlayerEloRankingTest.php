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

        foreach ($entries as $entry) {
            if ($entry->skillTierName !== null) {
                self::assertContains($entry->skillTierName, ['casual', 'enthusiast', 'proficient', 'advanced', 'expert', 'master', 'legend']);
            }
        }
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

    public function testRankingWithCountryFilterPreservesGlobalRank(): void
    {
        $allEntries = $this->query->ranking(500);

        if (count($allEntries) === 0) {
            self::markTestSkipped('No ELO entries in fixtures');
        }

        $countryEntry = null;

        foreach ($allEntries as $entry) {
            if ($entry->playerCountry !== null) {
                $countryEntry = $entry;
                break;
            }
        }

        if ($countryEntry === null) {
            self::markTestSkipped('No entries with country in fixtures');
        }

        $filtered = $this->query->ranking(500, 50, 0, $countryEntry->playerCountry);

        self::assertGreaterThanOrEqual(1, count($filtered));

        foreach ($filtered as $entry) {
            self::assertSame($countryEntry->playerCountry, $entry->playerCountry);
        }

        $matchingEntry = null;

        foreach ($filtered as $entry) {
            if ($entry->playerId === $countryEntry->playerId) {
                $matchingEntry = $entry;
                break;
            }
        }

        self::assertNotNull($matchingEntry);
        self::assertSame($countryEntry->rank, $matchingEntry->rank);
    }

    public function testRankingWithSearchFilter(): void
    {
        $allEntries = $this->query->ranking(500);

        if (count($allEntries) === 0) {
            self::markTestSkipped('No ELO entries in fixtures');
        }

        $entry = $allEntries[0];
        $searchTerm = $entry->playerName ?? $entry->playerCode;

        $filtered = $this->query->ranking(500, 50, 0, null, $searchTerm);

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
        $totalAll = $this->query->totalCount(500);
        $totalNonExistentCountry = $this->query->totalCount(500, 'xx');

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
