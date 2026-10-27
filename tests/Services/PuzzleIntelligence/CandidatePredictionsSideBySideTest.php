<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Message\RecordPuzzleDifficultyEstimates;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Query\GetPlayersWithPendingPredictions;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionReconstructor;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\CandidateFeatures;
use SpeedPuzzling\Web\Value\PuzzleDifficultyEstimateInput;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The candidate prediction model (v4, never shown - docs/features/puzzle-intelligence/prediction-history.md "The
 * candidate model (v4)") side by side with the shown one: fitted with it, written live in the add-time INSERT and by
 * the backfill, equal live and reconstructed as of now - and the shown predictions are the same with or without it.
 *
 * PLAYER_REGULAR has a 500-piece pace and has never solved PUZZLE_500_05 (2 difficulty indices) nor PUZZLE_500_04
 * (nobody has); PUZZLE_500_02 is a repeat. On a fitted model (FitsThePredictionModel).
 */
final class CandidatePredictionsSideBySideTest extends KernelTestCase
{
    use FitsThePredictionModel;

    private Connection $database;
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->database = $container->get(Connection::class);
        $this->messageBus = $container->get(MessageBusInterface::class);

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();
        $this->fitPredictionModel();

        $this->database->executeStatement("UPDATE puzzle SET image = 'box-500-05.jpg' WHERE id = :id", ['id' => PuzzleFixture::PUZZLE_500_05]);
        $this->storeEstimate(PuzzleFixture::PUZZLE_500_05, 0.3);
    }

    public function testTheFitStoresTheCandidateWithoutTouchingTheShownModel(): void
    {
        $provider = $this->provider();
        $candidate = $provider->candidate();

        self::assertNotNull($candidate, 'The daily fit fits the candidate too');
        self::assertNotNull($provider->candidateFittedAt());
        self::assertSame(CandidateFeatures::kept()->toArray(), $candidate->features()->toArray());
        self::assertArrayNotHasKey('index_noise', $provider->current()->toArray());

        /** @var array{index_count: int, sample_size: int}|false $evidence */
        $evidence = $this->database->fetchAssociative(
            'SELECT ce.index_count, pd.sample_size FROM candidate_puzzle_evidence ce JOIN puzzle_difficulty pd ON pd.puzzle_id = ce.puzzle_id WHERE ce.puzzle_id = :id',
            ['id' => PuzzleFixture::PUZZLE_500_05],
        );
        self::assertNotFalse($evidence);
        self::assertSame($evidence['sample_size'], $evidence['index_count'], 'The same indices the shown difficulty counts');
    }

    /**
     * The guard: every shown prediction (puzzle page, the add-time recap, the stored columns) is the same whether the
     * candidate is fitted or not.
     */
    public function testTheShownPredictionsAreTheSameWithAndWithoutTheCandidate(): void
    {
        $withCandidate = $this->shownPredictions();
        self::assertNotNull($this->getPlayerPrediction()->sideBySide(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_05)->candidate, 'Fitted: the candidate predicts');

        $this->database->executeStatement('DELETE FROM prediction_model_fit WHERE model_version = :version', ['version' => CandidatePredictionCalculator::MODEL_VERSION]);
        $this->database->executeStatement('DELETE FROM candidate_puzzle_evidence');
        $this->database->executeStatement('UPDATE difficulty_prior_puzzle SET candidate_prior_log = NULL');

        self::assertNull($this->getPlayerPrediction()->sideBySide(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_05)->candidate, 'Not fitted: no candidate row');
        self::assertEquals($withCandidate, $this->shownPredictions());
    }

    public function testALiveTimeWritesTheCandidateRowInTheSameInsert(): void
    {
        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        $firstTry = $this->addSoloTime(PuzzleFixture::PUZZLE_500_05, '00:45:00');

        /** @var list<array{sql: string}> $executed */
        $executed = $debugDataHolder->getData()['default'] ?? [];
        $inserts = array_filter(array_column($executed, 'sql'), static fn (string $sql): bool => str_starts_with($sql, 'INSERT INTO solving_time_prediction'));
        self::assertCount(1, $inserts, 'Four side rows, one INSERT');

        $row = $this->candidateRow($firstTry);
        self::assertNotFalse($row);
        self::assertSame('live', $row['source']);
        self::assertTrue($row['predictable']);
        self::assertSame(2, $row['k_indices']);

        $repeat = $this->addSoloTime(PuzzleFixture::PUZZLE_500_02, '00:26:40');
        $repeatRow = $this->candidateRow($repeat);
        self::assertNotFalse($repeatRow);
        self::assertSame('personal', $repeatRow['method']);
        self::assertStringStartsWith('w|', (string) $repeatRow['window_bucket']);
    }

    /**
     * Reconstructing a solve made right now gives exactly the live candidate prediction of a first try (with indices,
     * without). A repeat's candidate is the shown centre with the candidate's window - the shown repeat itself differs
     * live and reconstructed by its documented approximation (the global ratios' month snapshot), the candidate's part on
     * top of it does not.
     */
    public function testLiveAndReconstructedCandidatePredictionsAgreeAsOfNow(): void
    {
        foreach ([PuzzleFixture::PUZZLE_500_05, PuzzleFixture::PUZZLE_500_04, PuzzleFixture::PUZZLE_500_02] as $puzzleId) {
            $live = $this->getPlayerPrediction()->sideBySide(PlayerFixture::PLAYER_REGULAR, $puzzleId);
            self::assertNotNull($live->candidate?->result, $puzzleId);
            self::assertNotNull($live->statistical->result, $puzzleId);

            $timeId = $this->persistSoloTime($puzzleId, 2600, $this->now());
            $reconstructed = $this->reconstructor()->reconstructSideBySide(PlayerFixture::PLAYER_REGULAR, [$timeId])[$timeId];
            self::assertSame(CandidatePredictionCalculator::MODEL_VERSION, $reconstructed->candidate?->modelVersion);
            self::assertNotNull($reconstructed->candidate->result);
            self::assertNotNull($reconstructed->statistical->result);

            if ($live->firstTry) {
                self::assertEquals($live->candidate->result, $reconstructed->candidate->result, $puzzleId);

                continue;
            }

            self::assertSame($live->candidate->result->windowBucket, $reconstructed->candidate->result->windowBucket);
            self::assertEqualsWithDelta(
                $live->candidate->result->predictedSeconds / $live->statistical->result->predictedSeconds,
                $reconstructed->candidate->result->predictedSeconds / $reconstructed->statistical->result->predictedSeconds,
                0.002,
            );
        }
    }

    public function testTheBackfillWritesCandidateRowsOnlyOnceTheCandidateIsFitted(): void
    {
        $this->database->executeStatement('DELETE FROM solving_time_prediction WHERE predictor = :candidate', ['candidate' => 'candidate']);
        $fit = $this->database->fetchAssociative('SELECT fitted_at, parameters FROM prediction_model_fit WHERE model_version = :version', ['version' => CandidatePredictionCalculator::MODEL_VERSION]);
        self::assertNotFalse($fit);
        $this->database->executeStatement('DELETE FROM prediction_model_fit WHERE model_version = :version', ['version' => CandidatePredictionCalculator::MODEL_VERSION]);

        $this->backfill();
        self::assertSame(0, $this->candidateRowCount());

        $this->database->executeStatement(
            'INSERT INTO prediction_model_fit (model_version, fitted_at, parameters) VALUES (:version, :fittedAt, :parameters)',
            ['version' => CandidatePredictionCalculator::MODEL_VERSION, 'fittedAt' => $fit['fitted_at'], 'parameters' => $fit['parameters']],
        );
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->pendingPlayers()->all(withCandidateRows: true));

        self::assertGreaterThan(0, $this->backfill());

        /** @var int|string $soloTimes */
        $soloTimes = $this->database->fetchOne("SELECT count(*) FROM puzzle_solving_time WHERE player_id = :player AND puzzling_type = 'solo' AND seconds_to_solve IS NOT NULL", ['player' => PlayerFixture::PLAYER_REGULAR]);
        self::assertSame((int) $soloTimes, $this->candidateRowCount(), 'Every solo time of the player, first tries and repeats');
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->pendingPlayers()->all(withCandidateRows: true));
        self::assertSame(0, $this->backfill(), 'Done - the next run has nothing to do');
    }

    /**
     * @return array<string, mixed>
     */
    private function shownPredictions(): array
    {
        $get = $this->getPlayerPrediction();
        $shown = [];

        foreach ([PuzzleFixture::PUZZLE_500_05, PuzzleFixture::PUZZLE_500_04, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_1000_01] as $puzzleId) {
            $sideBySide = $get->sideBySide(PlayerFixture::PLAYER_REGULAR, $puzzleId);
            $shown[$puzzleId] = [
                $get->forPuzzle(PlayerFixture::PLAYER_REGULAR, $puzzleId),
                $get->forPuzzle(PlayerFixture::PLAYER_REGULAR, $puzzleId, allowPriorOnly: true),
                $sideBySide->statistical,
                $sideBySide->image,
                $sideBySide->blend,
            ];
        }

        return $shown;
    }

    private function storeEstimate(string $puzzleId, float $logDifficulty): void
    {
        /** @var string $image */
        $image = $this->database->fetchOne('SELECT image FROM puzzle WHERE id = :id', ['id' => $puzzleId]);

        $this->messageBus->dispatch(new RecordPuzzleDifficultyEstimates([
            new PuzzleDifficultyEstimateInput(
                puzzleId: $puzzleId,
                model: 'ridge-siglip2-dinov2',
                modelVersion: '1.0.0',
                logDifficulty: $logDifficulty,
                logP10: $logDifficulty - 0.2,
                logP90: $logDifficulty + 0.25,
                outOfFold: true,
                trainingSize: 4979,
                imageFingerprint: PuzzleImageFingerprint::of($image),
                computedAt: null,
            ),
        ]));
    }

    private function addSoloTime(string $puzzleId, string $time): string
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
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    private function persistSoloTime(string $puzzleId, int $seconds, DateTimeImmutable $solvedAt): string
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $player = $entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($player);
        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        self::assertNotNull($puzzle);

        $time = new PuzzleSolvingTime(
            id: Uuid::uuid7(),
            secondsToSolve: $seconds,
            player: $player,
            puzzle: $puzzle,
            trackedAt: $solvedAt,
            verified: true,
            team: null,
            finishedAt: $solvedAt,
            comment: null,
            finishedPuzzlePhoto: null,
            firstAttempt: false,
            unboxed: false,
        );

        $entityManager->persist($time);
        $entityManager->flush();

        return $time->id->toString();
    }

    /**
     * @return array{source: string, predictable: bool, method: null|string, k_indices: null|int, window_bucket: null|string}|false
     */
    private function candidateRow(string $timeId): array|false
    {
        /** @var array{source: string, predictable: bool, method: null|string, k_indices: null|int, window_bucket: null|string}|false */
        return $this->database->fetchAssociative(
            "SELECT source, predictable, method, k_indices, window_bucket FROM solving_time_prediction WHERE time_id = :id AND predictor = 'candidate' AND model_version = :version",
            ['id' => $timeId, 'version' => CandidatePredictionCalculator::MODEL_VERSION],
        );
    }

    private function candidateRowCount(): int
    {
        /** @var int|string $count */
        $count = $this->database->fetchOne(
            "SELECT count(*) FROM solving_time_prediction r JOIN puzzle_solving_time pst ON pst.id = r.time_id WHERE pst.player_id = :player AND r.predictor = 'candidate' AND r.model_version = :version",
            ['player' => PlayerFixture::PLAYER_REGULAR, 'version' => CandidatePredictionCalculator::MODEL_VERSION],
        );

        return (int) $count;
    }

    private function backfill(): int
    {
        $envelope = $this->messageBus->dispatch(new BackfillSolvingTimePredictions(PlayerFixture::PLAYER_REGULAR));

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $recorded = $envelope->last(HandledStamp::class)?->getResult();
        self::assertIsInt($recorded);

        return $recorded;
    }

    private function getPlayerPrediction(): GetPlayerPrediction
    {
        return self::getContainer()->get(GetPlayerPrediction::class);
    }

    private function reconstructor(): PredictionReconstructor
    {
        return self::getContainer()->get(PredictionReconstructor::class);
    }

    private function provider(): PredictionModelParametersProvider
    {
        return self::getContainer()->get(PredictionModelParametersProvider::class);
    }

    private function pendingPlayers(): GetPlayersWithPendingPredictions
    {
        return self::getContainer()->get(GetPlayersWithPendingPredictions::class);
    }

    private function now(): DateTimeImmutable
    {
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);

        return $clock->now();
    }
}
