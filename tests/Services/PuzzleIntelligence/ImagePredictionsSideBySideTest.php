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
use SpeedPuzzling\Web\Message\FitPredictionModel;
use SpeedPuzzling\Web\Message\RecordPuzzleDifficultyEstimates;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Query\GetPlayersWithPendingPredictions;
use SpeedPuzzling\Web\Query\GetTimesAwaitingImagePredictions;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionReconstructor;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\ImagePriorInput;
use SpeedPuzzling\Web\Value\PuzzleDifficultyEstimateInput;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The `image` and `blend` predictors side by side with the statistical one (Insights plan §5.4,
 * docs/features/puzzle-intelligence/prediction-history.md): written live in the add-time flush without a statement per
 * predictor, reconstructed from out-of-fold estimates only, equal live and reconstructed as of now, filled in by the
 * backfill once an estimate exists - and the statistical (shown) prediction never changes for it.
 *
 * PLAYER_REGULAR has a 500-piece pace and has never solved PUZZLE_500_05 (2 difficulty indices: shrunk) nor
 * PUZZLE_500_04 (nobody has: the prior alone). On a fitted model (FitsThePredictionModel).
 *
 * @phpstan-type SideRow array{predictor: string, source: string, predictable: bool, predicted_seconds: null|int, difficulty_source: null|string, k_indices: null|int, prior_log: null|float, image_model: null|string, image_kind: null|string, computed_at: string}
 */
final class ImagePredictionsSideBySideTest extends KernelTestCase
{
    use FitsThePredictionModel;

    private const string MODEL = 'ridge-siglip2-dinov2';
    private const float LOG_500_05 = 0.3;
    private const float LOG_500_04 = 0.2;

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
        $this->database->executeStatement("UPDATE puzzle SET image = 'box-500-04.jpg' WHERE id = :id", ['id' => PuzzleFixture::PUZZLE_500_04]);
        $this->storeEstimate(PuzzleFixture::PUZZLE_500_05, self::LOG_500_05, outOfFold: true);
        $this->storeEstimate(PuzzleFixture::PUZZLE_500_04, self::LOG_500_04, outOfFold: true);
        $this->storeArtworkKind(PuzzleFixture::PUZZLE_500_05, 'box_front');
    }

    /**
     * A first try added today: the statistical row (the shown prediction, shrunk toward the brand prior - exactly what
     * the puzzle page showed) and the image + blend rows resting on the picture's estimate - one INSERT for all three,
     * and the estimate read by the statement that reads the statistical inputs anyway.
     */
    public function testALiveFirstTryWritesTheThreePredictorsInOneInsertAndShowsTheStatisticalOne(): void
    {
        $shownBefore = $this->getPlayerPrediction()->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_05);
        self::assertNotNull($shownBefore);

        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        $timeId = $this->addSoloTime(PuzzleFixture::PUZZLE_500_05, '00:45:00');

        /** @var list<array{sql: string}> $executed */
        $executed = $debugDataHolder->getData()['default'] ?? [];
        $queries = array_column($executed, 'sql');
        $sideRowInserts = array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'INSERT INTO solving_time_prediction'));
        $estimateReads = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'puzzle_difficulty_estimate'));

        self::assertCount(1, $sideRowInserts, 'Three side rows, one INSERT');
        self::assertCount(1, $estimateReads, 'The estimate rides on the statistical read');
        self::assertStringContainsString('player_baseline', (string) array_values($estimateReads)[0]);

        /** @var array{predicted_seconds: null|int, predictable: null|bool} $shown */
        $shown = $this->database->fetchAssociative('SELECT predicted_seconds, predictable FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertTrue($shown['predictable']);
        self::assertSame($shownBefore->predictedSeconds, $shown['predicted_seconds'], 'The shown prediction is the statistical one, untouched by the picture');

        $rows = $this->sideRows($timeId);
        self::assertSame(['blend', 'image', 'statistical'], array_keys($rows));

        self::assertSame('live', $rows['statistical']['source']);
        self::assertSame('shrunk', $rows['statistical']['difficulty_source']);
        self::assertNotNull($shownBefore->priorLog);
        self::assertEqualsWithDelta($shownBefore->priorLog, $rows['statistical']['prior_log'], 1e-12, 'Shrunk toward the brand prior');
        self::assertNull($rows['statistical']['image_model']);

        self::assertTrue($rows['image']['predictable']);
        self::assertSame('live', $rows['image']['source']);
        self::assertSame('image', $rows['image']['difficulty_source']);
        self::assertSame(self::LOG_500_05, $rows['image']['prior_log']);
        self::assertSame(2, $rows['image']['k_indices'], 'The index count then');
        self::assertSame(self::MODEL, $rows['image']['image_model']);
        self::assertSame('box', $rows['image']['image_kind']);

        self::assertTrue($rows['blend']['predictable']);
        self::assertSame('shrunk', $rows['blend']['difficulty_source']);
        self::assertSame(self::LOG_500_05, $rows['blend']['prior_log'], 'Shrunk toward the picture');
        self::assertNotSame($rows['statistical']['predicted_seconds'], $rows['blend']['predicted_seconds']);
    }

    /**
     * An estimate no API would take (written by SQL: ×147, an interval over the whole log range) and a pace in the
     * millions: the time is saved all the same, its image row holds the largest number the column takes.
     */
    public function testAnExtremeEstimateNeverCostsTheTime(): void
    {
        $this->database->executeStatement(
            'UPDATE puzzle_difficulty_estimate SET log_difficulty = 4.99, log_p10 = -5, log_p90 = 5 WHERE puzzle_id = :id',
            ['id' => PuzzleFixture::PUZZLE_500_04],
        );
        $this->database->executeStatement(
            'UPDATE player_baseline SET baseline_seconds = 50000000 WHERE player_id = :player AND pieces_count = 500',
            ['player' => PlayerFixture::PLAYER_REGULAR],
        );

        $timeId = $this->addSoloTime(PuzzleFixture::PUZZLE_500_04, '00:50:00');

        self::assertSame(3000, $this->database->fetchOne('SELECT seconds_to_solve FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
        self::assertSame(TimePredictionCalculator::MAX_SECONDS, $this->sideRows($timeId)['image']['predicted_seconds']);
    }

    public function testARepeatGetsNoImageRows(): void
    {
        $timeId = $this->addSoloTime(PuzzleFixture::PUZZLE_500_02, '00:26:40');

        self::assertSame(['statistical'], array_keys($this->sideRows($timeId)));
    }

    /**
     * Reconstructing a solve made right now gives exactly the live image and blend predictions (one model, not two) -
     * on a shrunk puzzle and on one nobody solved, where image and blend are the same prediction.
     */
    public function testLiveAndReconstructedImageAndBlendPredictionsAgreeAsOfNow(): void
    {
        foreach ([PuzzleFixture::PUZZLE_500_05, PuzzleFixture::PUZZLE_500_04] as $puzzleId) {
            $live = $this->getPlayerPrediction()->sideBySide(PlayerFixture::PLAYER_REGULAR, $puzzleId);
            self::assertTrue($live->firstTry);
            self::assertNotNull($live->image?->result, $puzzleId);
            self::assertNotNull($live->blend?->result, $puzzleId);

            $timeId = $this->persistSoloTime(PlayerFixture::PLAYER_REGULAR, $puzzleId, 2600, $this->now());
            $reconstructed = $this->reconstructor()->reconstructSideBySide(PlayerFixture::PLAYER_REGULAR, [$timeId])[$timeId];

            self::assertEquals($live->statistical->result, $reconstructed->statistical->result, $puzzleId);
            self::assertEquals($live->image->result, $reconstructed->image?->result, $puzzleId);
            self::assertEquals($live->blend->result, $reconstructed->blend?->result, $puzzleId);
            self::assertEquals($live->imageEstimate, $reconstructed->imageEstimate);
        }

        self::assertEquals($live->image->result, $live->blend->result, 'Nobody solved PUZZLE_500_04: the blend is the picture alone');
    }

    /**
     * As-of correctness, conservatively: an estimate pushed with `outOfFold: false` (the pipeline's full model, for a
     * puzzle that had no label when it was trained - image-pipeline.md §7) feeds live predictions (made before the
     * solve existed) but never a reconstruction - the flag is the only evidence of what the model saw. The time waits -
     * no row, no "not predictable" - until the next derive pushes the estimate out of fold, then the backfill writes it.
     */
    public function testAnInSampleEstimateNeverFeedsAReconstruction(): void
    {
        $this->storeEstimate(PuzzleFixture::PUZZLE_500_05, self::LOG_500_05, outOfFold: false);

        self::assertNotNull($this->getPlayerPrediction()->sideBySide(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_05)->image, 'Live: any estimate of the current picture');

        // Back-dated: reconstructed, never live
        $timeId = $this->addSoloTime(PuzzleFixture::PUZZLE_500_05, '00:45:00', $this->now()->modify('-5 days')->setTime(0, 0));
        $this->backfill();

        self::assertSame(['statistical'], array_keys($this->sideRows($timeId)));
        self::assertSame('reconstructed', $this->sideRows($timeId)['statistical']['source']);
        self::assertNull($this->reconstructor()->reconstructSideBySide(PlayerFixture::PLAYER_REGULAR, [$timeId])[$timeId]->image);
        self::assertSame([], $this->awaiting()->ofPlayer(PlayerFixture::PLAYER_REGULAR));

        // The next derive: out of fold now
        $this->storeEstimate(PuzzleFixture::PUZZLE_500_05, 0.25, outOfFold: true);
        self::assertSame([$timeId], $this->awaiting()->ofPlayer(PlayerFixture::PLAYER_REGULAR));
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->pendingPlayers()->all());

        $this->backfill();

        $rows = $this->sideRows($timeId);
        self::assertSame('reconstructed', $rows['image']['source']);
        self::assertSame(0.25, $rows['image']['prior_log']);
        self::assertSame('reconstructed', $rows['blend']['source']);
        self::assertSame([], $this->awaiting()->ofPlayer(PlayerFixture::PLAYER_REGULAR));
    }

    /**
     * A puzzle without an estimate (a puzzle added together with its first time never has one): the live image and
     * blend rows say not predictable and name no estimate - replaced by a reconstruction once the puzzle has an
     * out-of-fold estimate.
     */
    public function testALiveRowWrittenWithoutAnEstimateIsReconstructedOnceThereIsOne(): void
    {
        $this->database->executeStatement('DELETE FROM puzzle_difficulty_estimate WHERE puzzle_id = :id', ['id' => PuzzleFixture::PUZZLE_500_05]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $timeId = $this->addSoloTime(PuzzleFixture::PUZZLE_500_05, '00:45:00');

        $rows = $this->sideRows($timeId);
        self::assertTrue($rows['statistical']['predictable']);

        foreach (['image', 'blend'] as $predictor) {
            self::assertFalse($rows[$predictor]['predictable'], $predictor);
            self::assertSame('live', $rows[$predictor]['source']);
            self::assertNull($rows[$predictor]['image_model']);
        }

        self::assertSame([], $this->awaiting()->ofPlayer(PlayerFixture::PLAYER_REGULAR), 'Waits for an estimate');

        $this->storeEstimate(PuzzleFixture::PUZZLE_500_05, self::LOG_500_05, outOfFold: true);
        self::assertSame([$timeId], $this->awaiting()->ofPlayer(PlayerFixture::PLAYER_REGULAR));

        $this->backfill();

        $rows = $this->sideRows($timeId);
        self::assertSame('live', $rows['statistical']['source'], 'The live statistical row is history');

        foreach (['image', 'blend'] as $predictor) {
            self::assertTrue($rows[$predictor]['predictable'], $predictor);
            self::assertSame('reconstructed', $rows[$predictor]['source']);
            self::assertSame(self::MODEL, $rows[$predictor]['image_model']);
            self::assertSame(self::LOG_500_05, $rows[$predictor]['prior_log']);
        }

        self::assertSame([], $this->awaiting()->ofPlayer(PlayerFixture::PLAYER_REGULAR));

        // A live row that rested on an estimate stays: history (another first try, on PUZZLE_500_04)
        $liveWithEstimate = $this->addSoloTime(PuzzleFixture::PUZZLE_500_04, '00:50:00');
        $this->backfill();
        self::assertSame('live', $this->sideRows($liveWithEstimate)['image']['source']);
    }

    /**
     * A live first try, then an earlier solve of the same puzzle back-dated in front of it: its stored statistical row
     * still says first try, but it reconstructs as a repeat. Once the puzzle has an estimate the work list names it -
     * once: the backfill closes it with reconstructed "not predictable" rows, it is never named again.
     */
    public function testATimeThatBecameARepeatLeavesTheWorkListAfterOneRun(): void
    {
        $this->database->executeStatement('DELETE FROM puzzle_difficulty_estimate WHERE puzzle_id = :id', ['id' => PuzzleFixture::PUZZLE_500_05]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $laterId = $this->addSoloTime(PuzzleFixture::PUZZLE_500_05, '00:45:00');
        self::assertSame('statistical', $this->database->fetchOne(
            "SELECT method FROM solving_time_prediction WHERE time_id = :id AND predictor = 'statistical'",
            ['id' => $laterId],
        ));

        $earlierId = $this->persistSoloTime(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_05, 2900, $this->now()->modify('-10 days'));
        $this->storeEstimate(PuzzleFixture::PUZZLE_500_05, self::LOG_500_05, outOfFold: true);

        self::assertSame([$laterId], $this->awaiting()->ofPlayer(PlayerFixture::PLAYER_REGULAR));
        self::assertGreaterThan(0, $this->backfill());

        $rows = $this->sideRows($laterId);

        foreach (['image', 'blend'] as $predictor) {
            self::assertSame('reconstructed', $rows[$predictor]['source'], $predictor);
            self::assertFalse($rows[$predictor]['predictable'], $predictor);
            self::assertNull($rows[$predictor]['image_model'], $predictor);
        }

        self::assertTrue($this->sideRows($earlierId)['image']['predictable'], 'The earlier solve is the first try now');
        self::assertSame([], $this->awaiting()->ofPlayer(PlayerFixture::PLAYER_REGULAR));
        self::assertSame(0, $this->backfill(), 'Nothing written on the second run');
    }

    /**
     * `--recompute-image`: a reconstruction older than the estimate its puzzle has now (a newer derive) is made again
     * from the new one - and only when asked.
     */
    public function testRecomputeImageReplacesReconstructionsOlderThanTheEstimate(): void
    {
        $timeId = $this->addSoloTime(PuzzleFixture::PUZZLE_500_05, '00:45:00', $this->now()->modify('-5 days')->setTime(0, 0));
        $this->backfill();
        self::assertSame(self::LOG_500_05, $this->sideRows($timeId)['image']['prior_log']);

        $this->database->executeStatement(
            "UPDATE puzzle_difficulty_estimate SET log_difficulty = 0.4, received_at = received_at + INTERVAL '1 hour' WHERE puzzle_id = :id",
            ['id' => PuzzleFixture::PUZZLE_500_05],
        );

        self::assertSame([], $this->awaiting()->ofPlayer(PlayerFixture::PLAYER_REGULAR));
        self::assertSame([$timeId], $this->awaiting()->ofPlayer(PlayerFixture::PLAYER_REGULAR, recomputeImage: true));

        $this->backfill();
        self::assertSame(self::LOG_500_05, $this->sideRows($timeId)['image']['prior_log'], 'Not without the option');

        $this->backfill(recomputeImage: true);
        self::assertSame(0.4, $this->sideRows($timeId)['image']['prior_log']);
        self::assertSame(0.4, $this->sideRows($timeId)['blend']['prior_log']);
    }

    /**
     * `--recompute-image` after a new fit: a reconstruction made before it (its window - the picture-only scale - may
     * have changed) is listed again, and only when asked.
     */
    public function testRecomputeImageListsReconstructionsOlderThanTheFit(): void
    {
        $timeId = $this->addSoloTime(PuzzleFixture::PUZZLE_500_05, '00:45:00', $this->now()->modify('-5 days')->setTime(0, 0));
        $this->backfill();
        $computedAt = $this->sideRows($timeId)['image']['computed_at'];
        $refit = fn (string $interval) => $this->database->executeStatement(
            "UPDATE prediction_model_fit SET fitted_at = CAST(:computedAt AS timestamp) + CAST(:interval AS interval)",
            ['computedAt' => $computedAt, 'interval' => $interval],
        );

        $refit('-1 hour');
        self::assertSame([], $this->awaiting()->ofPlayer(PlayerFixture::PLAYER_REGULAR, recomputeImage: true), 'Made after the fit');

        $refit('1 hour');
        self::assertSame([], $this->awaiting()->ofPlayer(PlayerFixture::PLAYER_REGULAR));
        self::assertSame([$timeId], $this->awaiting()->ofPlayer(PlayerFixture::PLAYER_REGULAR, recomputeImage: true));
    }

    /**
     * The fit stores every puzzle's blend prior next to the statistical one - equal to the last bit to the chain the
     * readers build from the stored sums and the current estimate - and its copy on the difficulty row.
     */
    public function testTheFitStoresTheBlendPriorEqualToTheReadersChain(): void
    {
        $this->messageBus->dispatch(new FitPredictionModel());

        /** @var TimePredictionCalculator $calculator */
        $calculator = self::getContainer()->get(TimePredictionCalculator::class);
        $rows = $this->database->fetchAllAssociative('
            SELECT p.id, p.pieces_count, p.manufacturer_id, g.sum_log_indices AS group_sum, g.index_count AS group_count,
                dpp.sum_log_indices AS own_sum, dpp.index_count AS own_count, dpp.prior_log, dpp.blend_prior_log,
                ' . StatisticalInputsSql::IMAGE_ESTIMATE_COLUMNS . '
            FROM puzzle p
            LEFT JOIN difficulty_prior_group g ON g.manufacturer_id = p.manufacturer_id AND g.pieces_count = p.pieces_count AND ' . StatisticalInputsSql::NOT_SECRET . '
            LEFT JOIN difficulty_prior_puzzle dpp ON dpp.puzzle_id = p.id
            ' . StatisticalInputsSql::imageEstimateJoins(outOfFoldOnly: false) . '
        ');
        $withImage = 0;

        foreach ($rows as $row) {
            /** @var array{id: string, pieces_count: int|string, manufacturer_id: null|string, group_sum: null|float|string, group_count: null|int|string, own_sum: null|float|string, own_count: null|int|string, prior_log: null|float, blend_prior_log: null|float} $row */
            $context = new PriorContext(
                puzzleId: $row['id'],
                piecesCount: (int) $row['pieces_count'],
                manufacturerId: $row['manufacturer_id'],
                brandPiecesGroupSumLog: (float) ($row['group_sum'] ?? 0.0),
                brandPiecesGroupCount: (int) ($row['group_count'] ?? 0),
                brandPiecesOwnSumLog: (float) ($row['own_sum'] ?? 0.0),
                brandPiecesOwnCount: (int) ($row['own_count'] ?? 0),
            );
            $estimate = ImagePriorInput::fromRow($row);

            self::assertSame($calculator->prior($context)?->logDifficulty, $row['prior_log'], $row['id']);
            self::assertSame($calculator->prior($context->withImageEstimate($estimate))?->logDifficulty, $row['blend_prior_log'], $row['id']);
            $withImage += $estimate !== null ? 1 : 0;
        }

        self::assertSame(2, $withImage);

        // Its copy on the difficulty row (what the Picker reads), also after the next batch run
        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = self::getContainer()->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();

        /** @var int|string $differing */
        $differing = $this->database->fetchOne('
            SELECT COUNT(*) FROM puzzle_difficulty pd
            LEFT JOIN difficulty_prior_puzzle dpp ON dpp.puzzle_id = pd.puzzle_id
            WHERE (pd.prior_log, pd.blend_prior_log) IS DISTINCT FROM (dpp.prior_log, dpp.blend_prior_log)
        ');
        self::assertSame(0, (int) $differing);
        /** @var false|float $copy */
        $copy = $this->database->fetchOne('SELECT blend_prior_log FROM puzzle_difficulty WHERE puzzle_id = :id', ['id' => PuzzleFixture::PUZZLE_500_05]);
        self::assertSame(self::LOG_500_05, $copy);
    }

    private function storeEstimate(string $puzzleId, float $logDifficulty, bool $outOfFold): void
    {
        /** @var string $image */
        $image = $this->database->fetchOne('SELECT image FROM puzzle WHERE id = :id', ['id' => $puzzleId]);

        $this->messageBus->dispatch(new RecordPuzzleDifficultyEstimates([
            new PuzzleDifficultyEstimateInput(
                puzzleId: $puzzleId,
                model: self::MODEL,
                modelVersion: '1.0.0',
                logDifficulty: $logDifficulty,
                logP10: $logDifficulty - 0.2,
                logP90: $logDifficulty + 0.25,
                outOfFold: $outOfFold,
                trainingSize: 4979,
                imageFingerprint: PuzzleImageFingerprint::of($image),
                computedAt: null,
            ),
        ]));
    }

    private function storeArtworkKind(string $puzzleId, string $kind): void
    {
        /** @var string $image */
        $image = $this->database->fetchOne('SELECT image FROM puzzle WHERE id = :id', ['id' => $puzzleId]);

        $this->database->executeStatement(
            "INSERT INTO puzzle_image_analysis (id, puzzle_id, analyzer, analyzer_version, image_fingerprint, result, analyzed_at, received_at)
            VALUES (:id, :puzzle, 'artwork', '1.0.0', :fingerprint, CAST(:result AS jsonb), NULL, NOW())",
            [
                'id' => Uuid::uuid7()->toString(),
                'puzzle' => $puzzleId,
                'fingerprint' => PuzzleImageFingerprint::of($image),
                'result' => json_encode(['kind' => $kind], JSON_THROW_ON_ERROR),
            ],
        );
    }

    private function addSoloTime(string $puzzleId, string $time, null|DateTimeImmutable $finishedAt = null): string
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

    private function persistSoloTime(string $playerId, string $puzzleId, int $seconds, DateTimeImmutable $solvedAt): string
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $player = $entityManager->find(Player::class, $playerId);
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

    private function backfill(bool $recomputeImage = false): int
    {
        $envelope = $this->messageBus->dispatch(new BackfillSolvingTimePredictions(PlayerFixture::PLAYER_REGULAR, recomputeImage: $recomputeImage));

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $recorded = $envelope->last(HandledStamp::class)?->getResult();
        self::assertIsInt($recorded);

        return $recorded;
    }

    /**
     * @return array<string, SideRow> keyed by predictor
     */
    private function sideRows(string $timeId): array
    {
        /** @var list<SideRow> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT predictor, source, predictable, predicted_seconds, difficulty_source, k_indices, prior_log, image_model, image_kind, computed_at
            FROM solving_time_prediction WHERE time_id = :id AND model_version = :version ORDER BY predictor',
            ['id' => $timeId, 'version' => TimePredictionCalculator::MODEL_VERSION],
        );

        return array_column($rows, null, 'predictor');
    }

    private function getPlayerPrediction(): GetPlayerPrediction
    {
        return self::getContainer()->get(GetPlayerPrediction::class);
    }

    private function reconstructor(): PredictionReconstructor
    {
        return self::getContainer()->get(PredictionReconstructor::class);
    }

    private function awaiting(): GetTimesAwaitingImagePredictions
    {
        return self::getContainer()->get(GetTimesAwaitingImagePredictions::class);
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
