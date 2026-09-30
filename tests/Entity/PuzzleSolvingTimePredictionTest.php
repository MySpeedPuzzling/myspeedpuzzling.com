<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use SpeedPuzzling\Web\Value\SolvingTimePrediction;
use SpeedPuzzling\Web\Value\TimePredictionMethod;
use SpeedPuzzling\Web\Value\TimePredictionSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The stored prediction of a solving time: what recordPrediction() writes, and which changes of the
 * time make the entity forget it (then the backfill evaluates it again).
 */
final class PuzzleSolvingTimePredictionTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testRecordsAPersonalPrediction(): void
    {
        $time = $this->soloTime();
        $computedAt = new DateTimeImmutable('2026-09-30 12:00:00');

        $time->recordPrediction(SolvingTimePrediction::predicted(new TimePredictionResult(
            predictedSeconds: 1600,
            rangeLowSeconds: 1500,
            rangeHighSeconds: 1700,
            difficultyForPlayer: 0.0,
            isPersonalized: true,
            personalSolveCount: 2,
            predictedAttemptNumber: 3,
            lastTimeSeconds: 1750,
        ), TimePredictionSource::Live), $computedAt);

        self::assertTrue($time->predictable);
        self::assertSame(TimePredictionMethod::Personal, $time->predictionMethod);
        self::assertSame(1600, $time->predictedSeconds);
        self::assertSame(1500, $time->predictedRangeLowSeconds);
        self::assertSame(1700, $time->predictedRangeHighSeconds);
        self::assertSame(3, $time->predictedAttemptNumber);
        self::assertSame(1750, $time->predictionLastTimeSeconds);
        self::assertSame(TimePredictionSource::Live, $time->predictionSource);
        self::assertSame($computedAt, $time->predictionComputedAt);
        self::assertSame(TimePredictionCalculator::MODEL_VERSION, $time->predictionModelVersion);
        self::assertFalse($time->isPredictionPending());
    }

    public function testRecordsAStatisticalPredictionWithoutAttemptData(): void
    {
        $time = $this->soloTime();

        $time->recordPrediction(SolvingTimePrediction::predicted(new TimePredictionResult(
            predictedSeconds: 2000,
            rangeLowSeconds: 1700,
            rangeHighSeconds: 2300,
            difficultyForPlayer: 1.05,
        ), TimePredictionSource::Reconstructed), new DateTimeImmutable());

        self::assertTrue($time->predictable);
        self::assertSame(TimePredictionMethod::Statistical, $time->predictionMethod);
        self::assertSame(2000, $time->predictedSeconds);
        self::assertNull($time->predictedAttemptNumber);
        self::assertNull($time->predictionLastTimeSeconds);
        self::assertSame(TimePredictionSource::Reconstructed, $time->predictionSource);
    }

    public function testRecordsThatItCouldNotBePredicted(): void
    {
        $time = $this->soloTime();

        $time->recordPrediction(SolvingTimePrediction::notPredictable(TimePredictionSource::Reconstructed), new DateTimeImmutable());

        self::assertFalse($time->predictable);
        self::assertNull($time->predictionMethod);
        self::assertNull($time->predictedSeconds);
        self::assertNull($time->predictedRangeLowSeconds);
        self::assertNull($time->predictedRangeHighSeconds);
        self::assertSame(TimePredictionSource::Reconstructed, $time->predictionSource);
        self::assertNotNull($time->predictionComputedAt);
        self::assertSame(TimePredictionCalculator::MODEL_VERSION, $time->predictionModelVersion);
        self::assertFalse($time->isPredictionPending());
    }

    public function testOnlySoloTimesWithSecondsArePending(): void
    {
        self::assertTrue($this->soloTime()->isPredictionPending());
        self::assertFalse($this->time(PuzzleSolvingTimeFixture::TIME_12)->isPredictionPending(), 'team time');
        self::assertFalse($this->time(PuzzleSolvingTimeFixture::TIME_46_RELAX_NO_FINISHED_AT)->isPredictionPending(), 'no seconds');
    }

    public function testChangingOnlyTheSecondsKeepsThePrediction(): void
    {
        $time = $this->predictedSoloTime();

        $this->modify($time, seconds: 999, comment: 'changed', firstAttempt: !$time->firstAttempt, unboxed: !$time->unboxed);

        self::assertTrue($time->predictable);
        self::assertSame(1600, $time->predictedSeconds);
    }

    public function testMovingTheSolveToAnotherDayForgetsThePrediction(): void
    {
        $time = $this->predictedSoloTime();

        $this->modify($time, finishedAt: new DateTimeImmutable('2020-01-01'));

        $this->assertForgotten($time);
        self::assertTrue($time->isPredictionPending());
    }

    /**
     * The edit form is date-only: re-saving a time that came with a time of day (API) sends midnight of
     * the same day - that is no move in the history.
     */
    public function testReSavingTheSameDayKeepsThePrediction(): void
    {
        $time = $this->predictedSoloTime();
        $this->modify($time, finishedAt: new DateTimeImmutable('2026-09-30 14:05:00'));
        $time->recordPrediction(SolvingTimePrediction::predicted(
            new TimePredictionResult(1600, 1500, 1700, 1.0),
            TimePredictionSource::Live,
        ), new DateTimeImmutable());

        $this->modify($time, finishedAt: new DateTimeImmutable('2026-09-30 00:00:00'));

        self::assertTrue($time->predictable);
        self::assertSame(1600, $time->predictedSeconds);
    }

    public function testClearingTheDateForgetsThePrediction(): void
    {
        $time = $this->predictedSoloTime();

        $this->modify($time, finishedAt: null);

        $this->assertForgotten($time);
    }

    public function testRemovingTheTimeForgetsThePrediction(): void
    {
        $time = $this->predictedSoloTime();

        $this->modify($time, seconds: null);

        $this->assertForgotten($time);
        self::assertFalse($time->isPredictionPending(), 'no seconds - nothing to predict any more');
    }

    public function testBecomingAPairForgetsThePrediction(): void
    {
        $time = $this->predictedSoloTime();

        $this->modify($time, group: $this->pairWithGuest($time));

        $this->assertForgotten($time);
        self::assertFalse($time->isPredictionPending());
    }

    public function testReplaceTeamForgetsOnlyWhenTheTypeChanges(): void
    {
        $time = $this->predictedSoloTime();
        $time->replaceTeam(null);
        self::assertTrue($time->predictable, 'still solo');

        $time->replaceTeam($this->pairWithGuest($time));
        $this->assertForgotten($time);
    }

    public function testTransferringOwnershipForgetsThePrediction(): void
    {
        $time = $this->predictedSoloTime();
        $newOwner = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_PRIVATE);
        self::assertNotNull($newOwner);

        $time->transferOwnership($newOwner, null);

        $this->assertForgotten($time);
    }

    private function soloTime(): PuzzleSolvingTime
    {
        // PLAYER_REGULAR, PUZZLE_500_01, 1800 s
        return $this->time(PuzzleSolvingTimeFixture::TIME_01);
    }

    private function predictedSoloTime(): PuzzleSolvingTime
    {
        $time = $this->soloTime();
        $time->recordPrediction(SolvingTimePrediction::predicted(
            new TimePredictionResult(1600, 1500, 1700, 1.0),
            TimePredictionSource::Live,
        ), new DateTimeImmutable());

        return $time;
    }

    private function time(string $id): PuzzleSolvingTime
    {
        $time = $this->entityManager->find(PuzzleSolvingTime::class, $id);
        self::assertNotNull($time);

        return $time;
    }

    private function pairWithGuest(PuzzleSolvingTime $time): PuzzlersGroup
    {
        return new PuzzlersGroup(null, [
            new Puzzler($time->player->id->toString(), null, null, null, false),
            new Puzzler(null, 'Guest Puzzler', null, null, false),
        ]);
    }

    private function modify(
        PuzzleSolvingTime $time,
        null|int|false $seconds = false,
        null|string $comment = null,
        null|PuzzlersGroup $group = null,
        null|DateTimeImmutable|false $finishedAt = false,
        null|bool $firstAttempt = null,
        null|bool $unboxed = null,
    ): void {
        $time->modify(
            $seconds === false ? $time->secondsToSolve : $seconds,
            $comment ?? $time->comment,
            $group ?? $time->team,
            $finishedAt === false ? $time->finishedAt : $finishedAt,
            $time->finishedPuzzlePhoto,
            $firstAttempt ?? $time->firstAttempt,
            $unboxed ?? $time->unboxed,
            $time->competition,
            $time->puzzlingTeam,
        );
    }

    private function assertForgotten(PuzzleSolvingTime $time): void
    {
        self::assertNull($time->predictable);
        self::assertNull($time->predictionMethod);
        self::assertNull($time->predictedSeconds);
        self::assertNull($time->predictedRangeLowSeconds);
        self::assertNull($time->predictedRangeHighSeconds);
        self::assertNull($time->predictedAttemptNumber);
        self::assertNull($time->predictionLastTimeSeconds);
        self::assertNull($time->predictionSource);
        self::assertNull($time->predictionComputedAt);
        self::assertNull($time->predictionModelVersion);
    }
}
