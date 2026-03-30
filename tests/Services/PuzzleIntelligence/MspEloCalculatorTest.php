<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspEloCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MspEloCalculatorTest extends KernelTestCase
{
    private MspEloCalculator $calculator;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var MspEloCalculator $calculator */
        $calculator = self::getContainer()->get(MspEloCalculator::class);
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
        $elo = $this->calculator->calculateForPlayer(PlayerFixture::PLAYER_REGULAR, 500);

        self::assertSame(0.0, $elo);
    }
}
