<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use ReflectionMethod;
use SpeedPuzzling\Web\Value\ImagePriorInput;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimelineLoader;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\UnboxedFirstTries;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\RepeatHistory;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PriorLookup;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionEngine;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\FirstAttemptSet;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidateModelFitter;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidateEngine;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\AttemptFlags;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\CandidateModelRecalculator;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\MessageHandler\BackfillSolvingTimePredictionsHandler;
use SpeedPuzzling\Web\Query\GetTimesAwaitingImagePredictions;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\SolvingTimePredictionRecordRepository;
use SpeedPuzzling\Web\Message\FitCandidatePredictionModel;
use SpeedPuzzling\Web\Message\FitPredictionModel;
use SpeedPuzzling\Web\Message\RefreshCandidatePredictionEvidence;
use SpeedPuzzling\Web\Message\RecordPuzzleDifficultyEstimates;
use SpeedPuzzling\Web\Query\GetCandidatePrediction;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Query\GetPlayersWithPendingPredictions;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionReconstructor;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\CandidateFeatures;
use SpeedPuzzling\Web\Value\PuzzleDifficultyEstimateInput;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use SpeedPuzzling\Web\Value\SolveConditions;
use SpeedPuzzling\Web\Value\SolvingTimePrediction;
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

    private const string FLAG = 'PREDICTION_CANDIDATE_ROWS';

    private Connection $database;
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        // PREDICTION_CANDIDATE_ROWS on for these tests (read when the container builds the service, at boot)
        $_ENV[self::FLAG] = $_SERVER[self::FLAG] = '1';
        self::bootKernel();
        $container = self::getContainer();

        $this->database = $container->get(Connection::class);
        $this->messageBus = $container->get(MessageBusInterface::class);

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();
        $this->fitPredictionModel();
        // The candidate's own daily command, after the shown fit
        $this->messageBus->dispatch(new FitCandidatePredictionModel());

        $this->database->executeStatement("UPDATE puzzle SET image = 'box-500-05.jpg' WHERE id = :id", ['id' => PuzzleFixture::PUZZLE_500_05]);
        $this->storeEstimate(PuzzleFixture::PUZZLE_500_05, 0.3);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_ENV[self::FLAG] = $_SERVER[self::FLAG] = '0';
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
        self::assertNotNull($this->liveCandidate(PuzzleFixture::PUZZLE_500_05)?->result, 'Fitted: the candidate predicts');

        $this->database->executeStatement('DELETE FROM prediction_model_fit WHERE model_version = :version', ['version' => CandidatePredictionCalculator::MODEL_VERSION]);
        $this->database->executeStatement('DELETE FROM candidate_puzzle_evidence');
        $this->database->executeStatement('DELETE FROM candidate_puzzle_prior');

        self::assertNull($this->liveCandidate(PuzzleFixture::PUZZLE_500_05), 'Not fitted: no candidate row');
        self::assertEquals($withCandidate, $this->shownPredictions());
    }

    /**
     * The shown fit alone never fits the candidate (its own command does) and the candidate's fit changes nothing of the
     * shown one: the shown row stays byte for byte.
     */
    public function testTheShownFitAndTheCandidateFitAreSeparate(): void
    {
        $shownBefore = $this->database->fetchOne('SELECT parameters FROM prediction_model_fit WHERE model_version = :version', ['version' => TimePredictionCalculator::MODEL_VERSION]);
        $this->database->executeStatement('DELETE FROM prediction_model_fit WHERE model_version = :version', ['version' => CandidatePredictionCalculator::MODEL_VERSION]);

        $this->messageBus->dispatch(new FitPredictionModel());
        self::assertNull($this->provider()->candidateFittedAt(), 'The shown fit writes no candidate row');

        $shown = $this->database->fetchOne('SELECT parameters FROM prediction_model_fit WHERE model_version = :version', ['version' => TimePredictionCalculator::MODEL_VERSION]);
        $this->messageBus->dispatch(new FitCandidatePredictionModel());
        self::assertNotNull($this->provider()->candidateFittedAt());
        self::assertSame($shown, $this->database->fetchOne('SELECT parameters FROM prediction_model_fit WHERE model_version = :version', ['version' => TimePredictionCalculator::MODEL_VERSION]));
        self::assertIsString($shownBefore);
    }

    public function testTheEvidenceRefreshRewritesWhatIsMissing(): void
    {
        /** @var int|string $rows */
        $rows = $this->database->fetchOne('SELECT count(*) FROM candidate_puzzle_evidence');
        self::assertGreaterThan(0, (int) $rows);
        $this->database->executeStatement('DELETE FROM candidate_puzzle_evidence');

        $result = $this->messageBus->dispatch(new RefreshCandidatePredictionEvidence())->last(HandledStamp::class)?->getResult();
        self::assertSame(['evidence_written' => (int) $rows, 'fitted' => true], $result);
        $again = $this->messageBus->dispatch(new RefreshCandidatePredictionEvidence())->last(HandledStamp::class)?->getResult();
        self::assertSame(['evidence_written' => 0, 'fitted' => true], $again, 'Nothing changed: nothing written');
    }

    /**
     * A candidate that fails (its table gone) costs neither the time nor its shown prediction and side rows - only the
     * candidate's row; and the shown statements of the add carry nothing of the candidate.
     */
    public function testAFailingCandidateCostsNeitherTheTimeNorItsShownPrediction(): void
    {
        $shownBefore = $this->getPlayerPrediction()->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_05);
        self::assertNotNull($shownBefore);
        $this->database->executeStatement('ALTER TABLE candidate_puzzle_evidence RENAME TO candidate_puzzle_evidence_gone');

        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();
        $timeId = $this->addSoloTime(PuzzleFixture::PUZZLE_500_05, '00:45:00');

        /** @var array{predicted_seconds: null|int, predictable: null|bool} $shown */
        $shown = $this->database->fetchAssociative('SELECT predicted_seconds, predictable FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertTrue($shown['predictable']);
        self::assertSame($shownBefore->predictedSeconds, $shown['predicted_seconds']);
        self::assertSame(['blend', 'image', 'statistical'], $this->database->fetchFirstColumn('SELECT predictor FROM solving_time_prediction WHERE time_id = :id ORDER BY predictor', ['id' => $timeId]));

        /** @var list<array{sql: string}> $executed */
        $executed = $debugDataHolder->getData()['default'] ?? [];

        foreach (array_column($executed, 'sql') as $sql) {
            if (str_contains($sql, 'player_baseline') && !str_contains($sql, 'candidate_puzzle_evidence')) {
                self::assertStringNotContainsString('candidate', $sql, 'The shown statistical read is the one without the candidate');
            }
        }
    }

    /**
     * The daily fit releases what its later steps do not read (the 512 MB container) - and fits exactly what the fit in
     * one call (the time-split evaluation) fits on the same inputs.
     */
    public function testTheReleasingDailyFitEqualsTheFitInOneCall(): void
    {
        $container = self::getContainer();
        /** @var CandidateModelRecalculator $recalculator */
        $recalculator = $container->get(CandidateModelRecalculator::class);
        $now = $this->now();
        $daily = $recalculator->fit($now)['candidate'];

        $shown = $this->provider()->current();
        /** @var array{0: PredictionEngine, 1: PriorLookup} $replay */
        $replay = new ReflectionMethod($recalculator, 'replay')->invoke($recalculator, $now, true, $shown->tau);
        /** @var array<string, ImagePriorInput> $images */
        $images = new ReflectionMethod($recalculator, 'imageEstimates')->invoke($recalculator, $now);
        [$engine, $priors] = $replay;
        $candidate = new CandidateEngine($engine, AttemptFlags::load($this->database, $container->get(PlayerBaselineCalculator::class), $engine->set));
        $from = (float) $now->modify('-' . PredictionModelRecalculator::FIT_WINDOW_MONTHS . ' months')->getTimestamp();
        $oneCall = new CandidateModelFitter()->fit($candidate, $priors, RepeatHistory::load($this->database, $from), $from, (float) $now->getTimestamp(), $now, $images, $shown, CandidateFeatures::kept(), UnboxedFirstTries::load($this->database));

        self::assertEquals(
            array_diff_key($oneCall->toArray(), ['summary' => true]),
            array_diff_key($daily->toArray(), ['summary' => true]),
        );
    }

    /**
     * The attempt flags are merged with the attempt set by the pace order: a time added after the set was read is
     * skipped, a time gone since gets no flags - every other attempt keeps its own.
     */
    public function testTheAttemptFlagsFollowTheSetWhenTimesChangeMeanwhile(): void
    {
        $container = self::getContainer();
        /** @var PlayerBaselineCalculator $baselineCalculator */
        $baselineCalculator = $container->get(PlayerBaselineCalculator::class);
        $set = FirstAttemptSet::build($baselineCalculator->iterateQualifyingFirstAttempts(paceOrder: true), $container->get(PaceTimelineLoader::class)->all());
        $before = AttemptFlags::load($this->database, $baselineCalculator, $set)->flags;
        self::assertCount($set->count(), $before);

        // A first try added meanwhile (nobody has solved PUZZLE_500_04)
        $this->addSoloTime(PuzzleFixture::PUZZLE_500_04, '00:45:00');
        self::assertSame($before, AttemptFlags::load($this->database, $baselineCalculator, $set)->flags);

        // A first attempt gone meanwhile - commented, so it has a flag to lose
        $gone = intdiv($set->count(), 2);
        $this->database->executeStatement("UPDATE puzzle_solving_time SET comment = 'interrupted' WHERE id = :id", ['id' => $set->id[$gone]]);
        $commented = AttemptFlags::load($this->database, $baselineCalculator, $set)->flags;
        self::assertSame(AttemptFlags::COMMENTED, $commented[$gone] & AttemptFlags::COMMENTED);
        $this->database->executeStatement('UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id', ['id' => $set->id[$gone]]);

        $after = AttemptFlags::load($this->database, $baselineCalculator, $set)->flags;
        self::assertSame(0, $after[$gone]);
        $after[$gone] = $before[$gone];
        self::assertSame($before, $after);
    }

    /**
     * PREDICTION_CANDIDATE_ROWS off (the production default until the first manual fill): the backfill writes no
     * candidate row - unless the message asks (`--candidate`).
     */
    public function testWithTheFlagOffOnlyAnExplicitBackfillWritesCandidateRows(): void
    {
        $this->database->executeStatement("DELETE FROM solving_time_prediction WHERE predictor = 'candidate'");
        $container = self::getContainer();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $handler = new BackfillSolvingTimePredictionsHandler(
            $container->get(PuzzleSolvingTimeRepository::class),
            $container->get(PredictionReconstructor::class),
            $container->get(SolvingTimePredictionRecordRepository::class),
            $container->get(ClockInterface::class),
            $this->provider(),
            $container->get(GetTimesAwaitingImagePredictions::class),
            candidateRows: false,
        );

        // Like the bus: in a transaction (the handler locks the player's times)
        $entityManager->wrapInTransaction(static fn () => $handler(new BackfillSolvingTimePredictions(PlayerFixture::PLAYER_REGULAR)));
        self::assertSame(0, $this->candidateRowCount());

        $entityManager->wrapInTransaction(static fn () => $handler(new BackfillSolvingTimePredictions(PlayerFixture::PLAYER_REGULAR, candidateRows: true)));
        self::assertGreaterThan(0, $this->candidateRowCount());
    }

    /**
     * A reconstruction whose candidate pass fails keeps every shown prediction.
     */
    public function testAFailingCandidateReconstructionKeepsTheShownPredictions(): void
    {
        $timeId = $this->persistSoloTime(PuzzleFixture::PUZZLE_500_05, 2600, $this->now());
        $this->database->executeStatement('ALTER TABLE candidate_puzzle_prior RENAME TO candidate_puzzle_prior_gone');

        $reconstructed = $this->reconstructor()->reconstructSideBySide(PlayerFixture::PLAYER_REGULAR, [$timeId])[$timeId];
        self::assertNotNull($reconstructed->statistical->result);
        self::assertNotNull($reconstructed->blend?->result);
        self::assertNull($reconstructed->candidate);
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
            $liveCandidate = $this->liveCandidate($puzzleId)?->result;
            self::assertNotNull($liveCandidate, $puzzleId);
            self::assertNotNull($live->statistical->result, $puzzleId);

            $timeId = $this->persistSoloTime($puzzleId, 2600, $this->now());
            $reconstructed = $this->reconstructor()->reconstructSideBySide(PlayerFixture::PLAYER_REGULAR, [$timeId])[$timeId];
            self::assertSame(CandidatePredictionCalculator::MODEL_VERSION, $reconstructed->candidate?->modelVersion);
            self::assertNotNull($reconstructed->candidate->result);
            self::assertNotNull($reconstructed->statistical->result);

            if ($live->firstTry) {
                self::assertEquals($liveCandidate, $reconstructed->candidate->result, $puzzleId);

                continue;
            }

            self::assertSame($liveCandidate->windowBucket, $reconstructed->candidate->result->windowBucket);
            self::assertEqualsWithDelta(
                $liveCandidate->predictedSeconds / $live->statistical->result->predictedSeconds,
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

    /**
     * The candidate's prediction of a solve added now (what SolvingTimePredictor writes) - null before its fit.
     */
    private function liveCandidate(string $puzzleId): null|SolvingTimePrediction
    {
        /** @var GetCandidatePrediction $getCandidatePrediction */
        $getCandidatePrediction = self::getContainer()->get(GetCandidatePrediction::class);

        return $getCandidatePrediction->forAddedTime(
            PlayerFixture::PLAYER_REGULAR,
            $puzzleId,
            $this->getPlayerPrediction()->sideBySide(PlayerFixture::PLAYER_REGULAR, $puzzleId),
            new SolveConditions(),
        );
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
