<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetSolvingTimePrediction;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Value\SolvingTimePrediction;
use SpeedPuzzling\Web\Value\TimePredictionSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetSolvingTimePredictionTest extends KernelTestCase
{
    private GetSolvingTimePrediction $query;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = self::getContainer()->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();

        $this->query = self::getContainer()->get(GetSolvingTimePrediction::class);
    }

    public function testReadsTheStoredPredictionBack(): void
    {
        $stored = new TimePredictionResult(
            predictedSeconds: 1600,
            rangeLowSeconds: 1500,
            rangeHighSeconds: 1700,
            difficultyForPlayer: 0.0,
            isPersonalized: true,
            personalSolveCount: 2,
            predictedAttemptNumber: 3,
            lastTimeSeconds: 1900,
        );
        $this->record(PuzzleSolvingTimeFixture::TIME_08, SolvingTimePrediction::predicted($stored, TimePredictionSource::Live));

        $prediction = $this->query->byTimeId(PuzzleSolvingTimeFixture::TIME_08);

        self::assertNotNull($prediction);
        self::assertSame(TimePredictionSource::Live, $prediction->source);
        self::assertEquals($stored, $prediction->result);
        self::assertEquals($stored, $this->query->resultForTime(PuzzleSolvingTimeFixture::TIME_08));
    }

    public function testNotPredictableIsStoredButHasNoResult(): void
    {
        $this->record(PuzzleSolvingTimeFixture::TIME_08, SolvingTimePrediction::notPredictable(TimePredictionSource::Reconstructed));

        $prediction = $this->query->byTimeId(PuzzleSolvingTimeFixture::TIME_08);

        self::assertNotNull($prediction);
        self::assertFalse($prediction->isPredictable());
        self::assertNull($this->query->resultForTime(PuzzleSolvingTimeFixture::TIME_08));
    }

    /**
     * While a time is pending it is computed - but only from the solves before it: TIME_07 is
     * PLAYER_REGULAR's 2nd of three PUZZLE_500_02 solves, so it knows TIME_06 only.
     */
    public function testAPendingTimeIsComputedFromTheSolvesBeforeIt(): void
    {
        self::assertNull($this->query->byTimeId(PuzzleSolvingTimeFixture::TIME_07));

        $result = $this->query->resultForTime(PuzzleSolvingTimeFixture::TIME_07);

        self::assertNotNull($result);
        self::assertTrue($result->isPersonalized);
        self::assertSame(1, $result->personalSolveCount);
        self::assertSame(2200, $result->lastTimeSeconds);
    }

    private function record(string $timeId, SolvingTimePrediction $prediction): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $time = $entityManager->find(PuzzleSolvingTime::class, $timeId);
        self::assertNotNull($time);
        $time->recordPrediction($prediction, new \DateTimeImmutable());
        $entityManager->flush();
        $entityManager->clear();
    }
}
