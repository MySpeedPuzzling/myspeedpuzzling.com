<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Component\PlayerSolvedPuzzles;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\PiecesRange;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class PlayerSolvedPuzzlesPiecesFilterTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testOnlyChipsWithResultsAreOfferedAndTheActiveOneStays(): void
    {
        $component = $this->results();
        $all = $this->piecesCounts($this->resultsOf($component));

        $offered = array_map(static fn (PiecesRange $preset): string => $preset->toParam(), $this->resultsOf($component)->getAvailablePiecePresets());

        foreach (PiecesRange::presets() as $preset) {
            $hasResults = array_filter($all, $preset->contains(...)) !== [];
            self::assertSame($hasResults, in_array($preset->toParam(), $offered, true), $preset->toParam());
        }

        // A chip the player has nothing for still shows while it is selected
        $component->set('piecesCountRange', '99-100');
        $offered = array_map(static fn (PiecesRange $preset): string => $preset->toParam(), $this->resultsOf($component)->getAvailablePiecePresets());
        self::assertContains('99-100', $offered);
    }

    public function testChipAndCustomRangeFilterTheResults(): void
    {
        $component = $this->results();
        self::assertContains(500, $this->piecesCounts($this->resultsOf($component)));

        $component->set('piecesCountRange', '500');
        $results = $this->resultsOf($component);
        self::assertSame(500, $results->piecesMin);
        self::assertSame(500, $results->piecesMax);
        self::assertNotSame([], $this->piecesCounts($results));
        self::assertSame([500], array_values(array_unique($this->piecesCounts($results))));
        self::assertSame(1, $results->getActiveFiltersCount());

        $component->set('piecesMax', null);
        $component->set('piecesMin', 501);
        $results = $this->resultsOf($component);
        self::assertSame('501-', $results->piecesCountRange);

        foreach ($this->piecesCounts($results) as $piecesCount) {
            self::assertGreaterThan(500, $piecesCount);
        }

        $component->call('resetFilters');
        $results = $this->resultsOf($component);
        self::assertNull($results->piecesCountRange);
        self::assertNull($results->piecesMin);
        self::assertSame(0, $results->getActiveFiltersCount());
    }

    private function results(): TestLiveComponent
    {
        $component = $this->createLiveComponent('PlayerSolvedPuzzles', ['playerId' => PlayerFixture::PLAYER_REGULAR], self::createClient());
        $component->setRouteLocale('en');

        return $component;
    }

    /**
     * The instance rebuilt from the props of the last response, with its results loaded as a render would
     */
    private function resultsOf(TestLiveComponent $component): PlayerSolvedPuzzles
    {
        $results = $component->component();
        self::assertInstanceOf(PlayerSolvedPuzzles::class, $results);
        $results->populate();

        return $results;
    }

    /**
     * @return list<int>
     */
    private function piecesCounts(PlayerSolvedPuzzles $results): array
    {
        $counts = [];

        foreach ([...$results->soloSolvedPuzzles, ...$results->duoSolvedPuzzles, ...$results->teamSolvedPuzzles] as $group) {
            foreach ($group as $solvedPuzzle) {
                $counts[] = $solvedPuzzle->piecesCount;
            }
        }

        return $counts;
    }
}
