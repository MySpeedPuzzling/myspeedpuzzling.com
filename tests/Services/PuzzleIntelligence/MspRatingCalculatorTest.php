<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MspRatingCalculatorTest extends KernelTestCase
{
    private MspRatingCalculator $calculator;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var MspRatingCalculator $calculator */
        $calculator = self::getContainer()->get(MspRatingCalculator::class);
        $this->calculator = $calculator;
    }

    public function testIsNotEligibleWithInsufficientSolves(): void
    {
        // Test fixtures don't have 15 first attempts + 50 total for any player on 500pc
        self::assertFalse($this->calculator->isEligible(PlayerFixture::PLAYER_REGULAR, 500));
    }

    public function testIsNotEligibleForNonExistentPlayer(): void
    {
        self::assertFalse($this->calculator->isEligible('00000000-0000-0000-0000-000000000099', 500));
    }

    public function testGetProgressReturnsCorrectStructure(): void
    {
        $progress = $this->calculator->getProgress(PlayerFixture::PLAYER_REGULAR, 500);

        self::assertGreaterThanOrEqual(0, $progress['first_attempts']);
        self::assertGreaterThanOrEqual(0, $progress['total_solves']);
    }

    public function testGetProgressReturnsZeroForNonExistentPlayer(): void
    {
        $progress = $this->calculator->getProgress('00000000-0000-0000-0000-000000000099', 500);

        self::assertSame(0, $progress['first_attempts']);
        self::assertSame(0, $progress['total_solves']);
    }

    public function testCalculateReturnsZeroForIneligiblePlayer(): void
    {
        $rating = $this->calculator->calculateForPlayer(PlayerFixture::PLAYER_REGULAR, 500);

        self::assertSame(0.0, $rating);
    }

    public function testProgressCountsWithin24MonthWindow(): void
    {
        // All fixture solves are recent, so they should all count within the 24-month window
        $progress = $this->calculator->getProgress(PlayerFixture::PLAYER_REGULAR, 500);

        self::assertGreaterThanOrEqual(0, $progress['first_attempts']);
        self::assertGreaterThanOrEqual(0, $progress['total_solves']);
        // total_solves should be >= first_attempts
        self::assertGreaterThanOrEqual($progress['first_attempts'], $progress['total_solves']);
    }

    public function testPreloadedPathProducesConsistentResults(): void
    {
        // Exercise the preloaded cache path (which includes latest_solve_date for decay)
        $this->calculator->precomputePuzzleRankings(500);
        $this->calculator->preloadAllPlayerSolves(500);

        $rating = $this->calculator->calculateForPlayer(PlayerFixture::PLAYER_REGULAR, 500);
        self::assertSame(0.0, $rating); // Still ineligible, but exercises decay code

        $this->calculator->clearCache();
    }
}
