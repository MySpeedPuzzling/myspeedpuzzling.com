<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Message\FitPredictionModel;
use SpeedPuzzling\Web\Query\GetPlayersWithPendingPredictions;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
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

        // Side-by-side rows are written only once the model is fitted
        $this->messageBus->dispatch(new FitPredictionModel());
    }

    /**
     * Before the model's first fit the backfill (the cron, an async back-dated add) writes the shown prediction of
     * pending times only - side-by-side rows of the current version would hold the defaults' predictions as if
     * fitted, and nothing would ever replace them.
     */
    public function testBeforeTheFirstFitNoSideBySideRowsAreWritten(): void
    {
        $this->database->executeStatement('DELETE FROM prediction_model_fit');

        self::assertGreaterThan(0, $this->backfill(PlayerFixture::PLAYER_REGULAR));

        /** @var int|string $sideRows */
        $sideRows = $this->database->fetchOne(
            'SELECT COUNT(*) FROM solving_time_prediction r JOIN puzzle_solving_time pst ON pst.id = r.time_id WHERE pst.player_id = :player',
            ['player' => PlayerFixture::PLAYER_REGULAR],
        );
        self::assertSame(0, (int) $sideRows);

        /** @var int|string $pending */
        $pending = $this->database->fetchOne(
            "SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :player AND puzzling_type = 'solo' AND seconds_to_solve IS NOT NULL AND predictable IS NULL",
            ['player' => PlayerFixture::PLAYER_REGULAR],
        );
        self::assertSame(0, (int) $pending, 'The shown predictions are written');

        /** @var GetPlayersWithPendingPredictions $getPlayersWithPendingPredictions */
        $getPlayersWithPendingPredictions = self::getContainer()->get(GetPlayersWithPendingPredictions::class);
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $getPlayersWithPendingPredictions->all(withSideRows: false), 'Not the cron\'s work any more before the fit');
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $getPlayersWithPendingPredictions->all(), 'Its side rows come once fitted');
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
     * Every evaluated solo time also gets the statistical predictor's side-by-side row of the current model - the
     * live one keeps its shown prediction and gets a reconstructed side row.
     */
    public function testWritesTheSideBySideRowOfEveryEvaluatedTime(): void
    {
        $this->backfill(PlayerFixture::PLAYER_REGULAR);

        /** @var int|string $solo */
        $solo = $this->database->fetchOne(
            "SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :player AND puzzling_type = 'solo' AND seconds_to_solve IS NOT NULL",
            ['player' => PlayerFixture::PLAYER_REGULAR],
        );
        /** @var list<array{source: string, model_version: int, predictable: bool, shown_predictable: bool, shown_seconds: null|int, predicted_seconds: null|int, k_indices: null|int}> $rows */
        $rows = $this->database->fetchAllAssociative("
            SELECT r.source, r.model_version, r.predictable, pst.predictable AS shown_predictable, pst.predicted_seconds AS shown_seconds, r.predicted_seconds, r.k_indices
            FROM solving_time_prediction r
            JOIN puzzle_solving_time pst ON pst.id = r.time_id
            WHERE pst.player_id = :player AND r.predictor = 'statistical'
        ", ['player' => PlayerFixture::PLAYER_REGULAR]);

        self::assertCount((int) $solo, $rows);

        foreach ($rows as $row) {
            self::assertSame('reconstructed', $row['source']);
            self::assertSame(TimePredictionCalculator::MODEL_VERSION, $row['model_version']);

            // The shown prediction is the side-by-side one, except a prior-only one (not shown)
            if ($row['k_indices'] !== 0) {
                self::assertSame($row['predictable'], $row['shown_predictable']);
                self::assertSame($row['predicted_seconds'], $row['shown_seconds']);
            }
        }
    }

    /**
     * --recompute-reconstructed replaces every reconstruction from before the current fit with one of it - shown (of
     * any model version) and side by side - never a live one, and a second run (or the rest of a stopped one) finds
     * only what is still older than the fit.
     */
    public function testRecomputingReplacesEveryReconstructionButNeverALiveOne(): void
    {
        $this->backfill(PlayerFixture::PLAYER_REGULAR);
        // The fit before everything the backfill just wrote, the tampered reconstructions before the fit
        $this->database->executeStatement("UPDATE prediction_model_fit SET fitted_at = '2026-06-01 00:00:00'");
        $this->database->executeStatement(
            "UPDATE puzzle_solving_time SET prediction_model_version = 2, predicted_seconds = 1234, prediction_source = 'reconstructed', prediction_computed_at = '2026-01-01 00:00:00' WHERE id = :id",
            ['id' => PuzzleSolvingTimeFixture::TIME_08],
        );
        $this->database->executeStatement(
            "UPDATE puzzle_solving_time SET predicted_seconds = 777, prediction_source = 'reconstructed', prediction_computed_at = '2026-01-01 00:00:00' WHERE id = :id",
            ['id' => PuzzleSolvingTimeFixture::TIME_07],
        );
        $this->database->executeStatement(
            "UPDATE puzzle_solving_time SET prediction_model_version = 2, predicted_seconds = 4321, prediction_source = 'live' WHERE id = :id",
            ['id' => PuzzleSolvingTimeFixture::TIME_01],
        );
        $this->database->executeStatement(
            "UPDATE solving_time_prediction SET predicted_seconds = 999, computed_at = '2026-01-01 00:00:00' WHERE time_id = :id AND source = 'reconstructed'",
            ['id' => PuzzleSolvingTimeFixture::TIME_07],
        );
        $this->database->executeStatement(
            "UPDATE solving_time_prediction SET predicted_seconds = 888, source = 'live' WHERE time_id = :id",
            ['id' => PuzzleSolvingTimeFixture::TIME_06],
        );
        // The command clears the entity manager after every player
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertSame(0, $this->backfill(PlayerFixture::PLAYER_REGULAR), 'Without the option a reconstruction stays');

        $envelope = $this->messageBus->dispatch(new BackfillSolvingTimePredictions(PlayerFixture::PLAYER_REGULAR, recomputeReconstructed: true));
        self::assertSame(2, $envelope->last(HandledStamp::class)?->getResult(), 'TIME_07 and TIME_08 - the rest is newer than the fit');

        /** @var array<string, array{predicted_seconds: int, prediction_model_version: int, prediction_source: string}> $shown */
        $shown = $this->database->fetchAllAssociativeIndexed(
            'SELECT id, predicted_seconds, prediction_model_version, prediction_source FROM puzzle_solving_time WHERE id IN (:a, :b, :c)',
            ['a' => PuzzleSolvingTimeFixture::TIME_08, 'b' => PuzzleSolvingTimeFixture::TIME_01, 'c' => PuzzleSolvingTimeFixture::TIME_07],
        );
        self::assertNotSame(1234, $shown[PuzzleSolvingTimeFixture::TIME_08]['predicted_seconds']);
        self::assertSame(TimePredictionCalculator::MODEL_VERSION, $shown[PuzzleSolvingTimeFixture::TIME_08]['prediction_model_version']);
        self::assertNotSame(777, $shown[PuzzleSolvingTimeFixture::TIME_07]['predicted_seconds'], 'A reconstruction of the current version too');
        self::assertSame(4321, $shown[PuzzleSolvingTimeFixture::TIME_01]['predicted_seconds']);
        self::assertSame('live', $shown[PuzzleSolvingTimeFixture::TIME_01]['prediction_source']);

        /** @var array<string, array{predicted_seconds: null|int, source: string}> $side */
        $side = $this->database->fetchAllAssociativeIndexed(
            'SELECT time_id, predicted_seconds, source FROM solving_time_prediction WHERE time_id IN (:a, :b)',
            ['a' => PuzzleSolvingTimeFixture::TIME_07, 'b' => PuzzleSolvingTimeFixture::TIME_06],
        );
        self::assertNotSame(999, $side[PuzzleSolvingTimeFixture::TIME_07]['predicted_seconds']);
        self::assertSame('reconstructed', $side[PuzzleSolvingTimeFixture::TIME_07]['source']);
        self::assertSame(888, $side[PuzzleSolvingTimeFixture::TIME_06]['predicted_seconds'], 'A live side row is history');
        self::assertSame('live', $side[PuzzleSolvingTimeFixture::TIME_06]['source']);

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $again = $this->messageBus->dispatch(new BackfillSolvingTimePredictions(PlayerFixture::PLAYER_REGULAR, recomputeReconstructed: true));
        self::assertSame(0, $again->last(HandledStamp::class)?->getResult(), 'Nothing older than the fit is left');
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
