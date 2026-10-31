<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use ReflectionClassConstant;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Message\FitCandidatePredictionModel;
use SpeedPuzzling\Web\Query\GetCandidatePrediction;
use SpeedPuzzling\Web\Query\GetPersonalEstimates;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Query\GetPredictionCalibration;
use SpeedPuzzling\Web\Query\GetPuzzlePickerSuggestions;
use SpeedPuzzling\Web\Query\GetSolvingTimePrediction;
use SpeedPuzzling\Web\Query\GetSuspiciousTimeCandidates;
use SpeedPuzzling\Web\Results\PuzzlePickerSuggestion;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\CandidatePictureProjections;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\RepeatResiduals;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeClassifier;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\DifficultySource;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\PaceSource;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PictureFactors;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use SpeedPuzzling\Web\Value\PuzzlePickerCriteria;
use SpeedPuzzling\Web\Value\ShownPredictionModel;
use SpeedPuzzling\Web\Value\SolveConditions;
use SpeedPuzzling\Web\Value\TasteModel;
use SpeedPuzzling\Web\Value\TasteState;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The shown model switch at v7 (PREDICTION_SHOWN_MODEL, docs/features/puzzle-intelligence/README.md "The shown model
 * switch"): every surface shows the candidate model's numbers - exactly the ones its side-by-side rows hold - the time
 * verification keeps the statistical model's, and a switch back recomputes the reconstructed shown predictions.
 *
 * On a fitted model and candidate (FitsThePredictionModel + the candidate's own fit), every fitted part of the candidate
 * given a value that moves the numbers: centre offsets, a taste model with a stored state and slope sums, picture
 * factors and kinds on the estimates of several puzzles, the image interval, a difficulty-adjusted pace offset.
 */
final class ShownModelV7Test extends KernelTestCase
{
    use FitsThePredictionModel;

    private const string FLAG = 'PREDICTION_SHOWN_MODEL';

    /** Pictures with an estimate: puzzle => [log difficulty, artwork kind] */
    private const array ESTIMATES = [
        PuzzleFixture::PUZZLE_500_05 => [0.3, 'box_front'],
        PuzzleFixture::PUZZLE_500_04 => [-0.2, 'assembled'],
        PuzzleFixture::PUZZLE_500_03 => [0.1, 'flat'],
        PuzzleFixture::PUZZLE_1000_01 => [0.05, 'box_angled'],
        PuzzleFixture::PUZZLE_1000_02 => [-0.1, 'flat'],
        PuzzleFixture::PUZZLE_300 => [0.2, 'assembled'],
        PuzzleFixture::PUZZLE_1500_01 => [0.15, 'box_front'],
    ];

    protected function setUp(): void
    {
        $this->boot('v7');
        $container = self::getContainer();

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();
        $this->fitPredictionModel();
        $this->bus()->dispatch(new FitCandidatePredictionModel());

        $taste = new TasteModel('1.0.0', self::weights(), 100.0, 30.0, 10.0);
        /** @var string $stored */
        $stored = $this->db()->fetchOne('SELECT parameters FROM prediction_model_fit WHERE model_version = :version', ['version' => CandidatePredictionCalculator::MODEL_VERSION]);
        $fitted = CandidateModelParameters::fromArray((array) json_decode($stored, true));
        $offsets = $fitted->centreOffsets;

        foreach ([PiecesGroup::UpTo300, PiecesGroup::FiveHundred, PiecesGroup::Thousand, PiecesGroup::From1500] as $index => $group) {
            $offsets[$group->value] = 0.013 * ($index + 1);

            foreach (PaceSource::cases() as $source) {
                $offsets[$group->value . '|' . $source->value . '|k1'] = -0.021 + 0.007 * $index;
                $offsets[$group->value . '|' . $source->value . '|k0|image_estimate'] = 0.031 - 0.003 * $index;
            }
        }

        $offsets['experience|lt100'] = 0.017;
        $offsets['experience|from100'] = -0.011;
        $this->db()->executeStatement('UPDATE prediction_model_fit SET parameters = CAST(:parameters AS jsonb) WHERE model_version = :version', [
            'parameters' => json_encode($fitted->with(
                centreOffsets: $offsets,
                repeatGapOffsets: ['lt30d' => 0.0213, '1_3m' => -0.0171, '3_12m' => 0.0377, 'gt12m' => 0.0519],
                taste: $taste,
                tasteVarianceWeight: 0.02,
                imageIntervalExponent: 0.5,
                imageIntervalReference: 0.012,
            )->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            'version' => CandidatePredictionCalculator::MODEL_VERSION,
        ]);

        foreach (self::ESTIMATES as $puzzleId => [$logDifficulty, $kind]) {
            $this->storeEstimate($puzzleId, $logDifficulty, $kind);
        }

        // The player's taste: several first tries on such pictures, slower than predicted, harder ones even more so
        $state = $taste->initialState();

        $puzzles = array_keys(self::ESTIMATES);

        for ($index = 0; $index < 60; $index++) {
            $puzzleId = $puzzles[$index % count($puzzles)];
            $residual = 0.4 * sin($index * 0.7) + 0.05 * self::factors($puzzleId)[0];
            $state = $state->with($taste->vector(new PictureFactors(self::factors($puzzleId), '1.0.0', ImageKind::Box), 500), $residual, false, 0.1 * ($index % 7) - 0.2);
        }

        self::assertNotNull($state->slopeCoefficients(TasteModel::SLOPE_LAMBDA, TasteModel::SLOPE_MINIMUM), 'The slope speaks');
        $this->storeTasteState('2020-01-01 10:00:00', $state);
        $this->storePaceOffset('2020-01-01 10:00:00', -0.0731);
        $this->syncProjections();
        $this->resetReaders();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_ENV[self::FLAG] = $_SERVER[self::FLAG] = 'v3';
    }

    public function testTheSwitchShowsTheCandidateModelVersion(): void
    {
        self::assertSame(new ReflectionClassConstant(CandidatePredictionCalculator::class, 'MODEL_VERSION')->getValue(), ShownPredictionModel::CANDIDATE_VERSION, 'A candidate bump must decide whether the shown switch follows');
        self::assertSame(ShownPredictionModel::CANDIDATE_VERSION, ShownPredictionModel::V7->modelVersion());
        self::assertSame(TimePredictionCalculator::MODEL_VERSION, ShownPredictionModel::V3->modelVersion());
        self::assertNotSame(new ReflectionClassConstant(TimePredictionCalculator::class, 'MODEL_VERSION')->getValue(), ShownPredictionModel::CANDIDATE_VERSION, 'A shown row tells the two models apart by its version');
        self::assertSame(ShownPredictionModel::V3, ShownPredictionModel::fromSetting('nonsense'));
        self::assertSame(ShownPredictionModel::V7, ShownPredictionModel::fromSetting(' V7 '));
        self::assertSame(ShownPredictionModel::V7, $this->getPlayerPrediction()->shownModel());
    }

    /**
     * The puzzle page (and API v1 me/predicted-time): the candidate's prediction - its side-by-side row's calculation, a
     * first try at k = 0 only where prior-only predictions are asked for, a repeat the statistical centre with the
     * candidate's window; the statistical model's when asked for V3 (the time verification's form check).
     */
    public function testThePuzzlePageShowsTheCandidatesPrediction(): void
    {
        $firstTries = 0;
        $repeats = 0;
        $hidden = 0;

        foreach ($this->puzzleIds() as $puzzleId) {
            $sideBySide = $this->getPlayerPrediction()->sideBySide(PlayerFixture::PLAYER_REGULAR, $puzzleId);
            $candidate = $this->candidate()->forAddedTime(PlayerFixture::PLAYER_REGULAR, $puzzleId, $sideBySide, new SolveConditions())?->result;
            $shown = $this->getPlayerPrediction()->forPuzzle(PlayerFixture::PLAYER_REGULAR, $puzzleId);

            if ($candidate !== null && $candidate->isPersonalized === false && $candidate->kIndices === 0) {
                self::assertNull($shown, "{$puzzleId}: no prior-only prediction on a surface");
                self::assertEquals($candidate, $this->getPlayerPrediction()->forPuzzle(PlayerFixture::PLAYER_REGULAR, $puzzleId, allowPriorOnly: true));
                $hidden++;

                continue;
            }

            self::assertEquals($candidate, $shown, $puzzleId);
            $candidate?->isPersonalized === true ? $repeats++ : $firstTries++;

            // The verification's own: the statistical model, whatever is shown
            $statistical = $sideBySide->statistical->forDisplay()->result;
            self::assertEquals($statistical, $this->getPlayerPrediction()->forPuzzle(PlayerFixture::PLAYER_REGULAR, $puzzleId, model: ShownPredictionModel::V3), $puzzleId);
        }

        self::assertGreaterThan(0, $firstTries);
        self::assertGreaterThan(0, $repeats);
        self::assertGreaterThan(0, $hidden);
    }

    /**
     * The bulk readers (collections, the Picker's cards, API v1 puzzles, Insights) and the personal estimates show what
     * the puzzle page shows.
     */
    public function testEveryReaderShowsThePuzzlePagesNumbers(): void
    {
        $ids = $this->puzzleIds();
        $bulk = $this->getPlayerPredictions()->forPuzzles(PlayerFixture::PLAYER_REGULAR, $ids);
        /** @var GetPersonalEstimates $getEstimates */
        $getEstimates = self::getContainer()->get(GetPersonalEstimates::class);
        $estimates = $getEstimates->forPuzzles(PlayerFixture::PLAYER_REGULAR, $ids, static fn (): bool => true);
        $pictures = 0;

        foreach ($ids as $puzzleId) {
            $shown = $this->getPlayerPrediction()->forPuzzle(PlayerFixture::PLAYER_REGULAR, $puzzleId);
            self::assertEquals($shown, $bulk[$puzzleId] ?? null, "bulk {$puzzleId}");

            $estimate = $estimates[$puzzleId] ?? null;

            if ($shown !== null) {
                self::assertEquals($shown, $estimate?->prediction, "estimate {$puzzleId}");
            } elseif ($estimate?->prediction !== null) {
                // k = 0: the picture's own prediction where its gate passed (here: always)
                self::assertSame(DifficultySource::Image, $estimate->prediction->difficultySource);
                self::assertEquals($this->getPlayerPrediction()->forPuzzle(PlayerFixture::PLAYER_REGULAR, $puzzleId, allowPriorOnly: true), $estimate->prediction);
                $pictures++;
            }
        }

        self::assertGreaterThan(0, $pictures, 'A picture-only estimate at k = 0');
    }

    /**
     * The Picker's pre-LIMIT SQL centre (CandidateCentreSql) is the cards' number for every candidate puzzle - the taste
     * term, the slope, the image prior's own variance, the offsets and the size curve's paces at sizes the player never
     * solved included; and a time budget keeps exactly the puzzles whose card says it fits.
     */
    public function testThePickerFiltersOnTheNumbersOfItsCards(): void
    {
        $playerId = PlayerFixture::PLAYER_REGULAR;
        $pick = $this->getPicker()->pick($this->criteria(['source' => 'any', 'lent' => '1', 'order' => 'gap_slower']), $playerId, 1000);
        $ids = array_map(static fn (PuzzlePickerSuggestion $suggestion): string => $suggestion->puzzleId, $pick->suggestions);
        $cards = $this->getPlayerPredictions()->forPuzzles($playerId, $ids);
        $sizes = [];
        $images = 0;

        foreach ($pick->suggestions as $suggestion) {
            $card = $cards[$suggestion->puzzleId] ?? null;
            self::assertSame($card?->predictedSeconds, $suggestion->predictedSeconds, $suggestion->puzzleId);

            if ($card !== null && $card->isPersonalized === false) {
                $sizes[$suggestion->piecesCount] = true;
                $images += $card->priorLog !== null && isset(self::ESTIMATES[$suggestion->puzzleId]) ? 1 : 0;
            }
        }

        self::assertGreaterThan(2, count($sizes), 'First tries at several sizes - the size curve');
        self::assertGreaterThan(0, $images, 'First tries resting on a picture estimate');

        $predicted = array_filter(array_map(static fn (null|TimePredictionResult $card): null|int => $card?->predictedSeconds, $cards));
        sort($predicted);
        $budget = (int) ceil($predicted[intdiv(count($predicted), 2)] / 60);
        $fitting = $this->getPicker()->pick($this->criteria(['source' => 'any', 'lent' => '1', 'predicted_max' => (string) $budget]), $playerId, 1000);
        $expected = array_keys(array_filter($cards, static fn (TimePredictionResult $card): bool => $card->predictedSeconds <= $budget * 60));
        $kept = array_map(static fn (PuzzlePickerSuggestion $suggestion): string => $suggestion->puzzleId, $fitting->suggestions);
        sort($expected);
        sort($kept);
        self::assertNotSame([], $kept);
        self::assertSame($expected, $kept);
    }

    /**
     * The Picker reads a picture's stored taste projection (candidate_picture_projection) only while it is current - a
     * re-pushed estimate, a missing row or another fit's W fall back to the factors themselves: the same numbers as the
     * cards either way; the stored sums are TasteModel::factorSums() to the bit, and a second sync writes nothing.
     */
    public function testThePickerAgreesWithStoredStaleAndMissingProjections(): void
    {
        /** @var list<array{puzzle_id: string, factor_sum1: float}> $stored */
        $stored = $this->db()->fetchAllAssociative('SELECT puzzle_id, factor_sum1 FROM candidate_picture_projection ORDER BY puzzle_id');
        self::assertCount(count(self::ESTIMATES), $stored, 'A projection of every picture with factors of the basis');
        self::assertSame(0, $this->syncProjections(), 'Nothing changed - nothing written');

        $weights = self::weights();
        $picture = new PictureFactors(self::factors($stored[0]['puzzle_id']), '1.0.0');
        self::assertSame(TasteModel::factorSums($weights, TasteModel::features($picture, 500, '1.0.0'))[0], (float) $stored[0]['factor_sum1']);

        $playerId = PlayerFixture::PLAYER_REGULAR;
        $criteria = $this->criteria(['source' => 'any', 'lent' => '1', 'order' => 'gap_slower']);
        $assertParity = function () use ($playerId, $criteria): void {
            $this->resetReaders();
            $pick = $this->getPicker()->pick($criteria, $playerId, 1000);
            $cards = $this->getPlayerPredictions()->forPuzzles($playerId, array_map(static fn (PuzzlePickerSuggestion $suggestion): string => $suggestion->puzzleId, $pick->suggestions));

            foreach ($pick->suggestions as $suggestion) {
                self::assertSame(($cards[$suggestion->puzzleId] ?? null)?->predictedSeconds, $suggestion->predictedSeconds, $suggestion->puzzleId);
            }
        };

        // Re-pushed since the last sync (a new receipt - its stored row made wrong, so reading it would show), and one row
        // gone: computed from the factors
        $this->db()->executeStatement("UPDATE puzzle_difficulty_estimate SET received_at = received_at + INTERVAL '1 second' WHERE puzzle_id = :id", ['id' => PuzzleFixture::PUZZLE_500_05]);
        $this->db()->executeStatement('UPDATE candidate_picture_projection SET factor_sum1 = factor_sum1 + 5 WHERE puzzle_id = :id', ['id' => PuzzleFixture::PUZZLE_500_05]);
        $this->db()->executeStatement('DELETE FROM candidate_picture_projection WHERE puzzle_id = :id', ['id' => PuzzleFixture::PUZZLE_1000_01]);
        $assertParity();

        // Stale for every picture (another fit's W), with numbers that would be wrong if read
        $this->db()->executeStatement("UPDATE candidate_picture_projection SET factor_sum1 = factor_sum1 + 5, taste_fitted_at = taste_fitted_at - INTERVAL '1 day'");
        $assertParity();

        self::assertGreaterThan(0, $this->syncProjections());
        $assertParity();
    }

    /**
     * An added time stores the candidate's prediction as its shown one (version 7) next to the statistical model's side
     * row, which the time verification and the repeats' residuals read; the recap reads the stored shown one.
     */
    public function testAnAddedTimeStoresTheCandidatesPrediction(): void
    {
        $expected = $this->getPlayerPrediction()->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_05);
        self::assertNotNull($expected);
        $timeId = $this->addSoloTime(PuzzleFixture::PUZZLE_500_05, '00:41:00');

        /** @var array{predictable: bool, predicted_seconds: int, prediction_model_version: int, prediction_source: string} $shown */
        $shown = $this->db()->fetchAssociative('SELECT predictable, predicted_seconds, prediction_model_version, prediction_source FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertSame(ShownPredictionModel::CANDIDATE_VERSION, $shown['prediction_model_version']);
        self::assertSame('live', $shown['prediction_source']);
        self::assertSame($expected->predictedSeconds, $shown['predicted_seconds']);
        self::assertSame($expected->predictedSeconds, $this->sideRow($timeId, 'candidate', CandidatePredictionCalculator::MODEL_VERSION)['predicted_seconds'] ?? null, 'Its side row - written whatever PREDICTION_CANDIDATE_ROWS says');
        self::assertNotFalse($this->sideRow($timeId, 'statistical', TimePredictionCalculator::MODEL_VERSION), 'The statistical model keeps its own row');

        /** @var GetSolvingTimePrediction $recap */
        $recap = self::getContainer()->get(GetSolvingTimePrediction::class);
        $result = $recap->resultForTime($timeId);
        self::assertNotNull($result);
        self::assertSame($expected->predictedSeconds, $result->predictedSeconds);
        self::assertTrue($result->calibratedWindow);
    }

    /**
     * The time verification's scan reads the statistical model's prediction of a time whose shown one is the candidate's -
     * the same number it read before the switch.
     */
    public function testTheScanReadsTheStatisticalModelWhateverIsShown(): void
    {
        $timeId = $this->addSoloTime(PuzzleFixture::PUZZLE_500_02, '00:39:00');
        $statistical = $this->sideRow($timeId, 'statistical', TimePredictionCalculator::MODEL_VERSION);
        self::assertNotFalse($statistical);
        /** @var int|string $shown */
        $shown = $this->db()->fetchOne('SELECT predicted_seconds FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertNotSame((int) $statistical['predicted_seconds'], (int) $shown, 'The candidate moved the shown centre');

        self::assertSame((int) $statistical['predicted_seconds'], $this->scanPrediction($timeId));

        // The same time as the statistical model had shown it: the scan reads the very same number
        $this->db()->executeStatement('UPDATE puzzle_solving_time SET predicted_seconds = :seconds, prediction_model_version = :version WHERE id = :id', [
            'seconds' => $statistical['predicted_seconds'],
            'version' => TimePredictionCalculator::MODEL_VERSION,
            'id' => $timeId,
        ]);
        self::assertSame((int) $statistical['predicted_seconds'], $this->scanPrediction($timeId));
    }

    /**
     * A repeat's residual is measured against the statistical centre also when the shown prediction was the candidate's
     * (Holt × its gap offset): the residuals - and so every window and fit - do not depend on the switch.
     */
    public function testRepeatResidualsReadTheStatisticalCentre(): void
    {
        $timeId = $this->addSoloTime(PuzzleFixture::PUZZLE_500_02, '00:39:00');
        $statistical = $this->sideRow($timeId, 'statistical', TimePredictionCalculator::MODEL_VERSION);
        self::assertNotFalse($statistical);
        self::assertSame('personal', $statistical['method']);

        /** @var string $json */
        $json = $this->db()->fetchOne('SELECT (' . RepeatResiduals::sql(false, false) . ')', ['playerId' => PlayerFixture::PLAYER_REGULAR]);
        $pairs = (array) json_decode($json, true);
        self::assertContains([39 * 60, (int) $statistical['predicted_seconds']], $pairs);
    }

    /**
     * The backfill writes the candidate's reconstruction as the shown prediction, and --recompute-reconstructed replaces a
     * reconstructed one of the other model - in both directions: switched back to v3, the statistical model's again.
     */
    public function testTheBackfillAndTheRecomputeFollowTheSwitch(): void
    {
        $timeId = $this->persistSoloTime(PuzzleFixture::PUZZLE_1000_01, 4100, new DateTimeImmutable(TestClock::FROZEN_NOW)->modify('-20 days'));
        $this->backfill(recompute: false);
        $candidate = $this->sideRow($timeId, 'candidate', CandidatePredictionCalculator::MODEL_VERSION);
        self::assertNotFalse($candidate);
        self::assertSame([ShownPredictionModel::CANDIDATE_VERSION, 'reconstructed', $candidate['predicted_seconds']], $this->shownColumns($timeId));

        // Back to v3: the recompute makes the statistical model's again (and nothing else would)
        $this->boot('v3');
        $this->backfill(recompute: false);
        self::assertSame(ShownPredictionModel::CANDIDATE_VERSION, $this->shownColumns($timeId)[0], 'Only a recompute replaces a reconstruction');
        $this->backfill(recompute: true);
        $statistical = $this->sideRow($timeId, 'statistical', TimePredictionCalculator::MODEL_VERSION);
        self::assertNotFalse($statistical);
        self::assertSame([TimePredictionCalculator::MODEL_VERSION, 'reconstructed', $statistical['predicted_seconds']], $this->shownColumns($timeId));

        // And at v7 again
        $this->boot('v7');
        $this->backfill(recompute: true);
        self::assertSame(ShownPredictionModel::CANDIDATE_VERSION, $this->shownColumns($timeId)[0]);
    }

    /**
     * The calibration report reads the shown model's live rows - the candidate's at v7.
     */
    public function testTheCalibrationReportReadsTheShownModelsRows(): void
    {
        $this->addSoloTime(PuzzleFixture::PUZZLE_500_05, '00:41:00');
        /** @var GetPredictionCalibration $calibration */
        $calibration = self::getContainer()->get(GetPredictionCalibration::class);
        $buckets = array_column($calibration->since(new DateTimeImmutable(TestClock::FROZEN_NOW)->modify('-1 day')), 'bucket');

        self::assertNotSame([], $buckets);

        foreach ($buckets as $bucket) {
            self::assertTrue(str_starts_with($bucket, 'v|') || str_starts_with($bucket, 'w|'), $bucket);
        }
    }

    /**
     * The puzzle page costs the same statements at v7 as at v3: a first try 2 (the attempts, the candidate's one-puzzle
     * statement in place of the statistical one), a repeat 4 (the candidate's fit rides on the ratio statement).
     */
    public function testThePuzzlePageCostsTheSameStatements(): void
    {
        self::assertSame(2, $this->statementsOf(PuzzleFixture::PUZZLE_500_05));
        self::assertSame(4, $this->statementsOf(PuzzleFixture::PUZZLE_500_02));

        $this->boot('v3');
        self::assertSame(2, $this->statementsOf(PuzzleFixture::PUZZLE_500_05));
        self::assertSame(4, $this->statementsOf(PuzzleFixture::PUZZLE_500_02));
    }

    private function statementsOf(string $puzzleId): int
    {
        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();
        $this->getPlayerPrediction()->forPuzzle(PlayerFixture::PLAYER_REGULAR, $puzzleId);

        /** @var list<array{sql: string}> $executed */
        $executed = $holder->getData()['default'] ?? [];

        return count($executed);
    }

    private function scanPrediction(string $timeId): null|int
    {
        /** @var GetSuspiciousTimeCandidates $candidates */
        $candidates = self::getContainer()->get(GetSuspiciousTimeCandidates::class);

        foreach ($candidates->batches(SuspiciousTimeClassifier::VERSION, new DateTimeImmutable(TestClock::FROZEN_NOW), 1000, PuzzleFixture::PUZZLE_500_02) as $batch) {
            foreach ($batch as $candidate) {
                if ($candidate->timeId === $timeId) {
                    return $candidate->predictedSeconds;
                }
            }
        }

        self::fail('The scan lists the time');
    }

    private function boot(string $model): void
    {
        self::ensureKernelShutdown();
        $_ENV[self::FLAG] = $_SERVER[self::FLAG] = $model;
        self::bootKernel();
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function bus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }

    private function resetReaders(): void
    {
        $this->getPlayerPredictions()->reset();
    }

    /**
     * What the candidate's jobs do after a fit or a refresh: the pictures' taste projections with the stored fit's W.
     */
    private function syncProjections(): int
    {
        $container = self::getContainer();
        /** @var PredictionModelParametersProvider $provider */
        $provider = $container->get(PredictionModelParametersProvider::class);
        $parameters = $provider->candidate();
        $fittedAt = $provider->candidateFittedAt();
        self::assertNotNull($parameters);
        self::assertNotNull($fittedAt);
        /** @var CandidatePictureProjections $projections */
        $projections = $container->get(CandidatePictureProjections::class);

        return $projections->sync($parameters, $fittedAt, new DateTimeImmutable(TestClock::FROZEN_NOW));
    }

    /**
     * @return list<string>
     */
    private function puzzleIds(): array
    {
        /** @var list<string> */
        return $this->db()->fetchFirstColumn('SELECT id FROM puzzle WHERE approved = true ORDER BY id');
    }

    /**
     * @return array{0: null|int, 1: null|string, 2: null|int}
     */
    private function shownColumns(string $timeId): array
    {
        /** @var array{prediction_model_version: null|int, prediction_source: null|string, predicted_seconds: null|int} $row */
        $row = $this->db()->fetchAssociative('SELECT prediction_model_version, prediction_source, predicted_seconds FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);

        return [$row['prediction_model_version'], $row['prediction_source'], $row['predicted_seconds']];
    }

    /**
     * @return array{predicted_seconds: int, method: null|string, source: string}|false
     */
    private function sideRow(string $timeId, string $predictor, int $version): array|false
    {
        /** @var array{predicted_seconds: int, method: null|string, source: string}|false */
        return $this->db()->fetchAssociative(
            'SELECT predicted_seconds, method, source FROM solving_time_prediction WHERE time_id = :id AND predictor = :predictor AND model_version = :version',
            ['id' => $timeId, 'predictor' => $predictor, 'version' => $version],
        );
    }

    private function backfill(bool $recompute): void
    {
        $envelope = $this->bus()->dispatch(new BackfillSolvingTimePredictions(PlayerFixture::PLAYER_REGULAR, recomputeReconstructed: $recompute));
        self::assertIsInt($envelope->last(HandledStamp::class)?->getResult());

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
    }

    /**
     * @param array<string, string> $query
     */
    private function criteria(array $query): PuzzlePickerCriteria
    {
        return PuzzlePickerCriteria::fromRequest(new Request($query), true, insightsAllowed: true, predictionsAllowed: true);
    }

    private function addSoloTime(string $puzzleId, string $time): string
    {
        $timeId = Uuid::uuid7();

        $this->bus()->dispatch(new AddPuzzleSolvingTime(
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

    private function storeEstimate(string $puzzleId, float $logDifficulty, string $kind): void
    {
        $image = 'shown-v7-' . $puzzleId . '.jpg';
        $fingerprint = PuzzleImageFingerprint::of($image);
        $this->db()->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => $image, 'id' => $puzzleId]);
        $this->db()->executeStatement('DELETE FROM puzzle_difficulty_estimate WHERE puzzle_id = :id', ['id' => $puzzleId]);
        $this->db()->executeStatement(
            "INSERT INTO puzzle_difficulty_estimate (puzzle_id, model, model_version, log_difficulty, log_p10, log_p90, out_of_fold, training_size, image_fingerprint, picture_factors, picture_factors_version, computed_at, received_at)
            VALUES (:puzzle, 'ridge-siglip2-dinov2', '1.2.1', :log, :p10, :p90, true, 4979, :fingerprint, CAST(:factors AS jsonb), '1.0.0', " . TestClock::SQL_NOW . ', ' . TestClock::SQL_NOW . ')',
            [
                'puzzle' => $puzzleId,
                'log' => $logDifficulty,
                'p10' => $logDifficulty - 0.17 - 0.01 * strlen($kind),
                'p90' => $logDifficulty + 0.23,
                'fingerprint' => $fingerprint,
                'factors' => json_encode(self::factors($puzzleId), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            ],
        );
        $this->db()->executeStatement(
            "INSERT INTO puzzle_image_analysis (id, puzzle_id, analyzer, analyzer_version, image_fingerprint, result, analyzed_at, received_at)
            VALUES (:id, :puzzle, 'artwork', '1.0.0', :fingerprint, CAST(:result AS jsonb), NULL, " . TestClock::SQL_NOW . ')
            ON CONFLICT DO NOTHING',
            ['id' => Uuid::uuid7()->toString(), 'puzzle' => $puzzleId, 'fingerprint' => $fingerprint, 'result' => json_encode(['kind' => $kind], JSON_THROW_ON_ERROR)],
        );
    }

    private function storeTasteState(string $validFrom, TasteState $state): void
    {
        $this->db()->executeStatement('
            INSERT INTO candidate_player_taste (player_id, valid_from, valid_from_tracked_at, first_tries, state, computed_at)
            VALUES (:player, :validFrom, :validFrom, :firstTries, CAST(:state AS jsonb), :validFrom)
        ', [
            'player' => PlayerFixture::PLAYER_REGULAR,
            'validFrom' => $validFrom,
            'firstTries' => $state->firstTries,
            'state' => json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        ]);
    }

    private function storePaceOffset(string $validFrom, float $offset): void
    {
        $this->db()->executeStatement('
            INSERT INTO candidate_pace_offset (player_id, pieces_count, valid_from, valid_from_tracked_at, pace_offset, computed_at)
            VALUES (:player, 500, :validFrom, :validFrom, :offset, :validFrom)
        ', ['player' => PlayerFixture::PLAYER_REGULAR, 'validFrom' => $validFrom, 'offset' => $offset]);
    }

    /**
     * Picture factors with every bit of a double in use (no round number survives a careless SQL twin)
     *
     * @return list<float>
     */
    private static function factors(string $puzzleId): array
    {
        $seed = crc32($puzzleId);

        return array_map(static fn (int $j): float => sin($seed * 0.001 + $j * 1.7) * 1.3, range(0, 15));
    }

    /**
     * @return list<list<float>>
     */
    private static function weights(): array
    {
        return array_map(
            static fn (int $r): array => array_map(static fn (int $j): float => cos($r * 2.3 + $j * 0.91) * 0.071, range(0, TasteModel::FEATURES - 1)),
            range(0, TasteModel::RANK - 1),
        );
    }

    private function getPlayerPrediction(): GetPlayerPrediction
    {
        return self::getContainer()->get(GetPlayerPrediction::class);
    }

    private function getPlayerPredictions(): GetPlayerPredictions
    {
        return self::getContainer()->get(GetPlayerPredictions::class);
    }

    private function getPicker(): GetPuzzlePickerSuggestions
    {
        return self::getContainer()->get(GetPuzzlePickerSuggestions::class);
    }

    private function candidate(): GetCandidatePrediction
    {
        return self::getContainer()->get(GetCandidatePrediction::class);
    }
}
