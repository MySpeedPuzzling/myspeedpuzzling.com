<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Tests\TestClock;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceHistoryReadiness;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerPaceHistoryWriter;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Right after the deploy player_pace_history is empty (the migration creates it, the first full
 * sync fills it). Until then nothing may replace a stored difficulty with one computed from the
 * empty history: the time added keeps the old difficulty and the next add is still predicted.
 */
final class PaceHistoryRolloutTest extends KernelTestCase
{
    private const array STORED_DIFFICULTY = ['difficulty_score' => 1.1, 'sample_size' => 12, 'indices_p25' => 1.0, 'indices_p75' => 1.2];

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->database = $container->get(Connection::class);

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();

        // The deploy: baselines and difficulties of the old code, an empty pace history, no marker
        $this->database->executeStatement('DELETE FROM player_pace_history');
        $this->database->executeStatement('DELETE FROM pace_history_build');
        $this->database->executeStatement(
            "INSERT INTO puzzle_difficulty (puzzle_id, difficulty_score, difficulty_tier, confidence, sample_size, indices_p25, indices_p75, computed_at)
            VALUES (:puzzle, 1.1, 3, 'medium', 12, 1.0, 1.2, " . TestClock::SQL_NOW . ")
            ON CONFLICT (puzzle_id) DO UPDATE SET difficulty_score = 1.1, difficulty_tier = 3, confidence = 'medium', sample_size = 12, indices_p25 = 1.0, indices_p75 = 1.2, sum_log_indices = NULL",
            ['puzzle' => PuzzleFixture::PUZZLE_500_04],
        );

        /** @var PaceHistoryReadiness $readiness */
        $readiness = $container->get(PaceHistoryReadiness::class);
        self::assertFalse($readiness->isBuilt());
    }

    public function testAnAddedTimeKeepsTheStoredDifficultyAndTheNextAddIsPredicted(): void
    {
        /** @var GetPlayerPrediction $getPlayerPrediction */
        $getPlayerPrediction = self::getContainer()->get(GetPlayerPrediction::class);
        $shownBefore = $getPlayerPrediction->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04);
        self::assertNotNull($shownBefore);
        self::assertFalse($shownBefore->isPersonalized);

        $this->addSoloTime(PlayerFixture::PLAYER_PRIVATE_USER_ID, '00:30:00', null);

        self::assertSame(self::STORED_DIFFICULTY, $this->storedDifficulty());

        $timeId = $this->addSoloTime(PlayerFixture::PLAYER_REGULAR_USER_ID, '00:31:00', null);

        /** @var array{predictable: null|bool, prediction_method: null|string, predicted_seconds: null|int} $prediction */
        $prediction = $this->database->fetchAssociative('SELECT predictable, prediction_method, predicted_seconds FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertTrue($prediction['predictable']);
        self::assertSame('statistical', $prediction['prediction_method']);
        self::assertSame($shownBefore->predictedSeconds, $prediction['predicted_seconds']);
        self::assertSame(self::STORED_DIFFICULTY, $this->storedDifficulty());
    }

    public function testABackDatedStatisticalTimeStaysPendingInsteadOfNotPredictable(): void
    {
        $timeId = $this->addSoloTime(PlayerFixture::PLAYER_REGULAR_USER_ID, '00:31:00', $this->now()->modify('-10 days')->setTime(0, 0));

        // The command's way (synchronously): the reconstruction finds no history to divide by
        $this->messageBus->dispatch(new BackfillSolvingTimePredictions(PlayerFixture::PLAYER_REGULAR));

        self::assertNull($this->database->fetchOne('SELECT predictable FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
    }

    public function testAPlayerRecalculationBeforeTheFirstFullOneKeepsTheDifficulties(): void
    {
        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = self::getContainer()->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate(PlayerFixture::PLAYER_REGULAR);

        self::assertSame(self::STORED_DIFFICULTY, $this->storedDifficulty());
    }

    public function testOnceTheHistoryIsBuiltAddsRecomputeTheDifficultyAgain(): void
    {
        /** @var PlayerPaceHistoryWriter $writer */
        $writer = self::getContainer()->get(PlayerPaceHistoryWriter::class);
        $writer->sync();

        $this->addSoloTime(PlayerFixture::PLAYER_PRIVATE_USER_ID, '00:30:00', null);

        self::assertNotSame(self::STORED_DIFFICULTY, $this->storedDifficulty());
    }

    private function addSoloTime(string $userId, string $time, null|DateTimeImmutable $finishedAt): string
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: PuzzleFixture::PUZZLE_500_04,
            competitionId: null,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: $finishedAt,
            firstAttempt: true,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    /**
     * @return array{difficulty_score: float, sample_size: int, indices_p25: float, indices_p75: float}
     */
    private function storedDifficulty(): array
    {
        /** @var array{difficulty_score: float|string|null, sample_size: int|string, indices_p25: float|string|null, indices_p75: float|string|null} $row */
        $row = $this->database->fetchAssociative(
            'SELECT difficulty_score, sample_size, indices_p25, indices_p75 FROM puzzle_difficulty WHERE puzzle_id = :puzzle',
            ['puzzle' => PuzzleFixture::PUZZLE_500_04],
        );

        return [
            'difficulty_score' => (float) $row['difficulty_score'],
            'sample_size' => (int) $row['sample_size'],
            'indices_p25' => (float) $row['indices_p25'],
            'indices_p75' => (float) $row['indices_p75'],
        ];
    }

    private function now(): DateTimeImmutable
    {
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);

        return $clock->now();
    }
}
