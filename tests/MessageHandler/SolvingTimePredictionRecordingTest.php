<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The prediction stored when a time is added or edited (SolvingTimePredictor): live for a solve of
 * today or yesterday, a background reconstruction for back-dated adds and for edits that made the
 * stored prediction obsolete.
 *
 * @phpstan-type PredictionRow array{predictable: null|bool, prediction_method: null|string, predicted_seconds: null|int, predicted_range_low_seconds: null|int, predicted_range_high_seconds: null|int, predicted_attempt_number: null|int, prediction_last_time_seconds: null|int, prediction_source: null|string, prediction_model_version: null|int}
 */
final class SolvingTimePredictionRecordingTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;
    private InMemoryTransport $asyncTransport;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->database = $container->get(Connection::class);

        $asyncTransport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $asyncTransport);
        $this->asyncTransport = $asyncTransport;

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();
    }

    /**
     * PLAYER_REGULAR solved PUZZLE_500_02 three times: the stored prediction is exactly what the puzzle
     * page showed right before the new solve - not what the recap would compute afterwards.
     */
    public function testAddingATimeOfTodayStoresThePredictionTheSiteShowedBefore(): void
    {
        /** @var GetPlayerPrediction $getPlayerPrediction */
        $getPlayerPrediction = self::getContainer()->get(GetPlayerPrediction::class);
        $shownBefore = $getPlayerPrediction->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_02);
        self::assertNotNull($shownBefore);

        $timeId = $this->addSoloTime(PuzzleFixture::PUZZLE_500_02, '00:26:40', finishedAt: null);

        $row = $this->predictionRow($timeId);
        self::assertTrue($row['predictable']);
        self::assertSame('live', $row['prediction_source']);
        self::assertSame('personal', $row['prediction_method']);
        self::assertSame($shownBefore->predictedSeconds, $row['predicted_seconds']);
        self::assertSame($shownBefore->rangeLowSeconds, $row['predicted_range_low_seconds']);
        self::assertSame($shownBefore->rangeHighSeconds, $row['predicted_range_high_seconds']);
        self::assertSame(4, $row['predicted_attempt_number']);
        self::assertSame(1700, $row['prediction_last_time_seconds']);
        self::assertSame(TimePredictionCalculator::MODEL_VERSION, $row['prediction_model_version']);
        self::assertSame([], $this->queuedReconstructions());
    }

    public function testAYesterdaySolveIsStillPredictedLive(): void
    {
        $timeId = $this->addSoloTime(PuzzleFixture::PUZZLE_500_02, '00:26:40', finishedAt: $this->now()->modify('-1 day')->setTime(0, 0));

        $row = $this->predictionRow($timeId);
        self::assertTrue($row['predictable']);
        self::assertSame('live', $row['prediction_source']);
    }

    /**
     * PUZZLE_9000 has no difficulty score and nobody solved it before: nothing to predict from.
     */
    public function testAddingATimeWithoutDataStoresNotPredictable(): void
    {
        $timeId = $this->addSoloTime(PuzzleFixture::PUZZLE_9000, '20:00:00', finishedAt: null);

        $row = $this->predictionRow($timeId);
        self::assertFalse($row['predictable']);
        self::assertSame('live', $row['prediction_source']);
        self::assertNull($row['predicted_seconds']);
        self::assertNull($row['prediction_method']);
    }

    public function testAGroupTimeIsNotEvaluated(): void
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_500_02,
            competitionId: null,
            time: '00:20:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: ['Guest Puzzler'],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        $row = $this->predictionRow($timeId->toString());
        self::assertNull($row['predictable']);
        self::assertNull($row['prediction_source']);
        self::assertSame([], $this->queuedReconstructions());
    }

    /**
     * Solved ten days ago: today's tables already hold everything after that day, so the time is left
     * pending and the player's reconstruction is queued (after the add has committed).
     */
    public function testABackDatedTimeIsReconstructedInTheBackground(): void
    {
        $timeId = $this->addSoloTime(PuzzleFixture::PUZZLE_500_02, '00:26:40', finishedAt: $this->now()->modify('-10 days')->setTime(0, 0));

        self::assertNull($this->predictionRow($timeId)['predictable']);
        self::assertSame([PlayerFixture::PLAYER_REGULAR], $this->queuedReconstructions());
    }

    /**
     * A failing read must not cost the player their time: the prediction runs in a savepoint, the time
     * is saved unevaluated and handed to the background reconstruction.
     */
    public function testAFailingPredictionNeverLosesTheTime(): void
    {
        // The personal prediction reads global_improvement_ratio; nothing else in the add does
        $this->database->executeStatement('ALTER TABLE global_improvement_ratio RENAME TO global_improvement_ratio_gone');

        $timeId = $this->addSoloTime(PuzzleFixture::PUZZLE_500_02, '00:26:40', finishedAt: null);

        $this->database->executeStatement('ALTER TABLE global_improvement_ratio_gone RENAME TO global_improvement_ratio');

        /** @var int|string|false $seconds */
        $seconds = $this->database->fetchOne('SELECT seconds_to_solve FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertSame(1600, (int) $seconds);
        self::assertNull($this->predictionRow($timeId)['predictable']);
        self::assertSame([PlayerFixture::PLAYER_REGULAR], $this->queuedReconstructions());
    }

    public function testEditingOnlyTheSecondsKeepsThePrediction(): void
    {
        $this->messageBus->dispatch(new BackfillSolvingTimePredictions(PlayerFixture::PLAYER_REGULAR));
        $before = $this->predictionRow(PuzzleSolvingTimeFixture::TIME_07);
        self::assertNotNull($before['predictable']);

        $this->edit(PuzzleSolvingTimeFixture::TIME_07, time: '00:30:00', finishedAt: $this->finishedAtOf(PuzzleSolvingTimeFixture::TIME_07));

        self::assertSame($before, $this->predictionRow(PuzzleSolvingTimeFixture::TIME_07));
        self::assertSame([], $this->queuedReconstructions());
    }

    public function testMovingTheSolveToAnotherDayQueuesAReconstruction(): void
    {
        $this->messageBus->dispatch(new BackfillSolvingTimePredictions(PlayerFixture::PLAYER_REGULAR));
        self::assertNotNull($this->predictionRow(PuzzleSolvingTimeFixture::TIME_07)['predictable']);

        $this->edit(PuzzleSolvingTimeFixture::TIME_07, time: '00:31:40', finishedAt: $this->now()->modify('-40 days')->setTime(0, 0));

        self::assertNull($this->predictionRow(PuzzleSolvingTimeFixture::TIME_07)['predictable']);
        self::assertSame([PlayerFixture::PLAYER_REGULAR], $this->queuedReconstructions());
        self::assertSame([[PuzzleSolvingTimeFixture::TIME_07]], $this->queuedReevaluations(), 'named explicitly - see BackfillSolvingTimePredictions');
    }

    /**
     * Queueing the reconstruction must not clear the entity manager in the middle of the web request
     * (the add is followed by more work on entities loaded before it, e.g. FinishStopwatch).
     */
    public function testQueueingAReconstructionKeepsTheEntityManager(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $player = $entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($player);

        $this->addSoloTime(PuzzleFixture::PUZZLE_500_02, '00:26:40', finishedAt: $this->now()->modify('-10 days')->setTime(0, 0));

        self::assertSame([PlayerFixture::PLAYER_REGULAR], $this->queuedReconstructions());
        self::assertTrue($entityManager->contains($player));
    }

    private function addSoloTime(string $puzzleId, string $time, null|DateTimeImmutable $finishedAt): string
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: $puzzleId,
            competitionId: null,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: $finishedAt,
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    private function edit(string $timeId, string $time, null|DateTimeImmutable $finishedAt): void
    {
        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleSolvingTimeId: $timeId,
            competitionId: null,
            time: $time,
            comment: null,
            groupPlayers: [],
            finishedAt: $finishedAt,
            finishedPuzzlesPhoto: null,
            firstAttempt: false,
            unboxed: false,
        ));
    }

    /**
     * @return PredictionRow
     */
    private function predictionRow(string $timeId): array
    {
        /** @var PredictionRow|false $row */
        $row = $this->database->fetchAssociative(
            'SELECT predictable, prediction_method, predicted_seconds, predicted_range_low_seconds, predicted_range_high_seconds,
                predicted_attempt_number, prediction_last_time_seconds, prediction_source, prediction_model_version
             FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );
        self::assertNotFalse($row);

        return $row;
    }

    /**
     * @return list<string> player ids of the queued async reconstructions
     */
    private function queuedReconstructions(): array
    {
        $playerIds = [];

        foreach ($this->asyncTransport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof BackfillSolvingTimePredictions) {
                $playerIds[] = $message->playerId;
            }
        }

        return $playerIds;
    }

    /**
     * @return list<list<string>>
     */
    private function queuedReevaluations(): array
    {
        $timeIds = [];

        foreach ($this->asyncTransport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof BackfillSolvingTimePredictions) {
                $timeIds[] = $message->reevaluateTimeIds;
            }
        }

        return $timeIds;
    }

    private function finishedAtOf(string $timeId): DateTimeImmutable
    {
        /** @var string|false $finishedAt */
        $finishedAt = $this->database->fetchOne('SELECT finished_at FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertIsString($finishedAt);

        return new DateTimeImmutable($finishedAt);
    }

    private function now(): DateTimeImmutable
    {
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);

        return $clock->now();
    }
}
