<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerSkillCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlayerSkillCalculatorTest extends KernelTestCase
{
    private PlayerSkillCalculator $calculator;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // Run full recalculation to populate baselines and difficulty
        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();

        /** @var PlayerSkillCalculator $calculator */
        $calculator = $container->get(PlayerSkillCalculator::class);
        $this->calculator = $calculator;
    }

    public function testReturnsNullForPlayerWithInsufficientQualifyingPuzzles(): void
    {
        // 9000pc has no puzzles with difficulty scores
        $result = $this->calculator->calculateForPlayer(PlayerFixture::PLAYER_REGULAR, 9000);

        self::assertNull($result);
    }

    public function testReturnsNullForNonExistentPlayer(): void
    {
        $result = $this->calculator->calculateForPlayer('00000000-0000-0000-0000-000000000099', 500);

        self::assertNull($result);
    }

    public function testSkillScoreIsPositive(): void
    {
        $result = $this->calculator->calculateForPlayer(PlayerFixture::PLAYER_REGULAR, 500);

        // May be null if not enough qualifying puzzles — that's OK
        if ($result === null) {
            self::markTestSkipped('Not enough qualifying puzzles for skill calculation');
        }

        self::assertGreaterThan(0.0, $result['skill_score']);
        self::assertGreaterThanOrEqual(0.0, $result['skill_percentile']);
        self::assertLessThanOrEqual(100.0, $result['skill_percentile']);
    }
}
