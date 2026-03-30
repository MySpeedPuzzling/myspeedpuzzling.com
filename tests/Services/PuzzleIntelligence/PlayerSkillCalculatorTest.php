<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerSkillCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlayerSkillCalculatorTest extends KernelTestCase
{
    private PlayerSkillCalculator $calculator;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();

        /** @var PlayerSkillCalculator $calculator */
        $calculator = $container->get(PlayerSkillCalculator::class);
        $this->calculator = $calculator;
    }

    public function testReturnsNullForNonExistentPieceCount(): void
    {
        $result = $this->calculator->calculateForPlayer(PlayerFixture::PLAYER_REGULAR, 9000);

        self::assertNull($result);
    }

    public function testReturnsNullForNonExistentPlayer(): void
    {
        $result = $this->calculator->calculateForPlayer('00000000-0000-0000-0000-000000000099', 500);

        self::assertNull($result);
    }

    public function testReturnsNullWithInsufficientSolversPerPuzzle(): void
    {
        // v2 requires 20+ first-attempt solvers per puzzle. Test fixtures have
        // only 5 players, so the calculator correctly returns null.
        $result = $this->calculator->calculateForPlayer(PlayerFixture::PLAYER_REGULAR, 500);

        self::assertNull($result);
    }
}
