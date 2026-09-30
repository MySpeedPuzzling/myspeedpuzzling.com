<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Query\GetPlayersWithPendingPredictions;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Value\SolvingTimePrediction;
use SpeedPuzzling\Web\Value\TimePredictionSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class BackfillSolvingTimePredictionsHandlerTest extends KernelTestCase
{
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
    }

    public function testEvaluatesEveryPendingSoloTimeOfThePlayer(): void
    {
        $recorded = $this->backfill(PlayerFixture::PLAYER_REGULAR);

        /** @var list<array{id: string, puzzling_type: string, seconds_to_solve: null|int, predictable: null|bool, prediction_source: null|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT id, puzzling_type, seconds_to_solve, predictable, prediction_source FROM puzzle_solving_time WHERE player_id = :playerId',
            ['playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        $evaluated = 0;

        foreach ($rows as $row) {
            if ($row['puzzling_type'] === 'solo' && $row['seconds_to_solve'] !== null) {
                self::assertNotNull($row['predictable'], $row['id']);
                self::assertSame('reconstructed', $row['prediction_source']);
                $evaluated++;
            } else {
                self::assertNull($row['predictable'], 'group times and times without seconds stay unevaluated: ' . $row['id']);
            }
        }

        self::assertGreaterThan(0, $evaluated);
        self::assertSame($evaluated, $recorded);

        // PLAYER_REGULAR's 3rd PUZZLE_500_02 solve had two earlier attempts to predict from
        /** @var array{predictable: bool, prediction_method: string, predicted_attempt_number: int, prediction_last_time_seconds: int} $time08 */
        $time08 = $this->database->fetchAssociative(
            'SELECT predictable, prediction_method, predicted_attempt_number, prediction_last_time_seconds FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_08],
        );
        self::assertTrue($time08['predictable']);
        self::assertSame('personal', $time08['prediction_method']);
        self::assertSame(3, $time08['predicted_attempt_number']);
        self::assertSame(1900, $time08['prediction_last_time_seconds']);
    }

    public function testNeverReplacesALivePrediction(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $time = $entityManager->find(PuzzleSolvingTime::class, PuzzleSolvingTimeFixture::TIME_01);
        self::assertNotNull($time);
        $time->recordPrediction(
            SolvingTimePrediction::predicted(new TimePredictionResult(1234, 1100, 1400, 1.0), TimePredictionSource::Live),
            new DateTimeImmutable('2026-09-01 10:00:00'),
        );
        $entityManager->flush();
        $entityManager->clear();

        $this->backfill(PlayerFixture::PLAYER_REGULAR);

        /** @var array{predicted_seconds: int, prediction_source: string, prediction_computed_at: string} $row */
        $row = $this->database->fetchAssociative(
            'SELECT predicted_seconds, prediction_source, prediction_computed_at FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_01],
        );
        self::assertSame(1234, $row['predicted_seconds']);
        self::assertSame('live', $row['prediction_source']);
        self::assertSame('2026-09-01 10:00:00', $row['prediction_computed_at']);
    }

    /**
     * An edit names its time: whatever a reconstruction that was already running wrote for the time's
     * old data is replaced.
     */
    public function testReevaluatesTheTimesAnEditNamed(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $time = $entityManager->find(PuzzleSolvingTime::class, PuzzleSolvingTimeFixture::TIME_08);
        self::assertNotNull($time);
        $time->recordPrediction(
            SolvingTimePrediction::predicted(new TimePredictionResult(1234, 1100, 1400, 1.0), TimePredictionSource::Reconstructed),
            new DateTimeImmutable('2026-09-01 10:00:00'),
        );
        $entityManager->flush();
        $entityManager->clear();

        $this->messageBus->dispatch(new BackfillSolvingTimePredictions(PlayerFixture::PLAYER_REGULAR, [PuzzleSolvingTimeFixture::TIME_08]));

        /** @var array{predicted_seconds: int, predicted_attempt_number: int, prediction_computed_at: string} $row */
        $row = $this->database->fetchAssociative(
            'SELECT predicted_seconds, predicted_attempt_number, prediction_computed_at FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_08],
        );
        self::assertNotSame(1234, $row['predicted_seconds']);
        self::assertSame(3, $row['predicted_attempt_number']);
        self::assertNotSame('2026-09-01 10:00:00', $row['prediction_computed_at']);
    }

    /**
     * Recording a prediction is no change of the solving time: no PuzzleSolvingTimeModified, so the
     * incremental insights recalculation (which rewrites puzzle_difficulty) does not run per row.
     */
    public function testRecordsNoDomainEvents(): void
    {
        $this->database->executeStatement("UPDATE puzzle_difficulty SET computed_at = '2000-01-01 00:00:00'");

        $this->backfill(PlayerFixture::PLAYER_REGULAR);

        /** @var int|string $touched */
        $touched = $this->database->fetchOne("SELECT COUNT(*) FROM puzzle_difficulty WHERE computed_at <> '2000-01-01 00:00:00'");
        self::assertSame(0, (int) $touched);
    }

    public function testASecondRunHasNothingToDo(): void
    {
        self::assertGreaterThan(0, $this->backfill(PlayerFixture::PLAYER_REGULAR));
        self::assertSame(0, $this->backfill(PlayerFixture::PLAYER_REGULAR));
    }

    /**
     * The whole fixture set, the way the command walks it: afterwards nobody has a pending time.
     */
    public function testEveryFixturePlayerCanBeBackfilled(): void
    {
        /** @var GetPlayersWithPendingPredictions $getPlayersWithPendingPredictions */
        $getPlayersWithPendingPredictions = self::getContainer()->get(GetPlayersWithPendingPredictions::class);
        $players = $getPlayersWithPendingPredictions->all();
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $players);

        foreach ($players as $playerId) {
            $this->backfill($playerId);
        }

        self::assertSame([], $getPlayersWithPendingPredictions->all());

        /** @var int|string $predicted */
        $predicted = $this->database->fetchOne('SELECT COUNT(*) FROM puzzle_solving_time WHERE predictable = true');
        /** @var int|string $notPredictable */
        $notPredictable = $this->database->fetchOne('SELECT COUNT(*) FROM puzzle_solving_time WHERE predictable = false');
        self::assertGreaterThan(0, (int) $predicted);
        self::assertGreaterThan(0, (int) $notPredictable);
    }

    private function backfill(string $playerId): int
    {
        $envelope = $this->messageBus->dispatch(new BackfillSolvingTimePredictions($playerId));

        /** @var int $recorded */
        $recorded = $envelope->last(HandledStamp::class)?->getResult();

        return $recorded;
    }
}
