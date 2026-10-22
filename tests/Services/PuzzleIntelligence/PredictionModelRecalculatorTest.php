<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use SpeedPuzzling\Web\Tests\TestClock;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\FitPredictionModel;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionEvaluator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The prediction model's jobs (PredictionModelRecalculator): the batch's drift and spread on the pace history, the daily
 * fit with the BrandPiecesPrior groups - and what predictions take from them.
 */
final class PredictionModelRecalculatorTest extends KernelTestCase
{
    private PuzzleIntelligenceRecalculator $recalculator;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $this->database = $container->get(Connection::class);
    }

    public function testTheBatchWritesOnlyChangedDriftAndTheDailyFitStoresTheModel(): void
    {
        $this->recalculator->recalculate();
        $this->fit();

        $fitted = self::getContainer()->get(PredictionModelParametersProvider::class)->current();
        self::assertNotNull($fitted->fittedAt, 'The fit is stored for the current model version');

        /** @var int|string $withoutDrift */
        $withoutDrift = $this->database->fetchOne('SELECT COUNT(*) FROM player_pace_history WHERE drift IS NULL OR spread IS NULL');
        self::assertSame(0, (int) $withoutDrift, 'Every pace row carries its drift and spread');
        /** @var int|string $groups */
        $groups = $this->database->fetchOne('SELECT COUNT(*) FROM difficulty_prior_group');
        self::assertGreaterThan(0, (int) $groups);

        // The next batch run, with the fit the drift was just written with: nothing to write
        self::assertSame(0, $this->recalculator->recalculate()['drift_rows_written']);
    }

    /**
     * The fit stores every puzzle's finished prior (difficulty_prior_puzzle.prior_log - what the Puzzle Picker reads):
     * equal to the last bit to the prior the other readers build from the stored sums, a row for every puzzle that has
     * one - also without indices of its own - and none for a puzzle without.
     */
    public function testTheFitStoresEveryPuzzlesFinishedPrior(): void
    {
        $this->recalculator->recalculate();
        $this->fit();

        /** @var TimePredictionCalculator $calculator */
        $calculator = self::getContainer()->get(TimePredictionCalculator::class);
        /** @var list<array{id: string, pieces_count: int|string, manufacturer_id: null|string, group_sum: null|float|string, group_count: null|int|string, own_sum: null|float|string, own_count: null|int|string, prior_log: null|float|string, has_row: bool}> $rows */
        $rows = $this->database->fetchAllAssociative('
            SELECT p.id, p.pieces_count, p.manufacturer_id, g.sum_log_indices AS group_sum, g.index_count AS group_count,
                dpp.sum_log_indices AS own_sum, dpp.index_count AS own_count, dpp.prior_log, dpp.puzzle_id IS NOT NULL AS has_row
            FROM puzzle p
            LEFT JOIN difficulty_prior_group g ON g.manufacturer_id = p.manufacturer_id AND g.pieces_count = p.pieces_count AND ' . StatisticalInputsSql::NOT_SECRET . '
            LEFT JOIN difficulty_prior_puzzle dpp ON dpp.puzzle_id = p.id
        ', ['now' => self::clockNow(self::getContainer())]);
        $withoutIndices = 0;

        foreach ($rows as $row) {
            $prior = $calculator->prior(new PriorContext(
                puzzleId: $row['id'],
                piecesCount: (int) $row['pieces_count'],
                manufacturerId: $row['manufacturer_id'],
                brandPiecesGroupSumLog: (float) ($row['group_sum'] ?? 0.0),
                brandPiecesGroupCount: (int) ($row['group_count'] ?? 0),
                brandPiecesOwnSumLog: (float) ($row['own_sum'] ?? 0.0),
                brandPiecesOwnCount: (int) ($row['own_count'] ?? 0),
            ))?->logDifficulty;

            self::assertSame($prior, $row['prior_log'] !== null ? (float) $row['prior_log'] : null, $row['id']);

            if ($prior !== null) {
                self::assertTrue($row['has_row'], $row['id']);
                $withoutIndices += (int) $row['own_count'] === 0 ? 1 : 0;
            }
        }

        self::assertGreaterThan(0, $withoutIndices, 'Puzzles without indices of their own get their prior too');

        // Its copy on the difficulty row (what the Puzzle Picker reads), also after the next batch run
        $this->recalculator->recalculate();
        /** @var int|string $copiesDiffering */
        $copiesDiffering = $this->database->fetchOne('
            SELECT COUNT(*) FROM puzzle_difficulty pd
            LEFT JOIN difficulty_prior_puzzle dpp ON dpp.puzzle_id = pd.puzzle_id
            WHERE pd.prior_log IS DISTINCT FROM dpp.prior_log
        ');
        self::assertSame(0, (int) $copiesDiffering);
        /** @var int|string $copies */
        $copies = $this->database->fetchOne('SELECT COUNT(*) FROM puzzle_difficulty WHERE prior_log IS NOT NULL');
        self::assertGreaterThan(0, (int) $copies);
    }

    private function fit(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new FitPredictionModel());
    }

    /**
     * Secret puzzles (hide_until / hide_image_until in the future) are no training row of a prior and get none.
     */
    public function testSecretPuzzlesHaveNoPriorAndFeedNone(): void
    {
        $this->recalculator->recalculate();
        $this->fit();

        /** @var int|string $secretShares */
        $secretShares = $this->database->fetchOne('
            SELECT COUNT(*) FROM difficulty_prior_puzzle dpp
            JOIN puzzle p ON p.id = dpp.puzzle_id
            WHERE p.hide_until > ' . TestClock::SQL_NOW . ' OR p.hide_image_until > ' . TestClock::SQL_NOW . '
        ');
        self::assertSame(0, (int) $secretShares);

        /** @var GetPlayerPrediction $getPlayerPrediction */
        $getPlayerPrediction = self::getContainer()->get(GetPlayerPrediction::class);
        $secret = $getPlayerPrediction->forPuzzle(PlayerFixture::PLAYER_REGULAR, CompetitionApiFixture::PUZZLE_PLATFORM_HIDDEN, allowPriorOnly: true);
        self::assertNull($secret?->priorLog);
    }

    /**
     * A 500-piece puzzle of a brand with enough indices that nobody has solved (PUZZLE_HIDDEN_IMAGE is hidden only
     * inside its competition round, the platform shows it): predicted from its prior alone when asked (side by side),
     * never shown.
     */
    public function testAPuzzleWithoutIndicesIsPredictedFromItsPriorOnlyWhenAsked(): void
    {
        $this->recalculator->recalculate();
        $this->fit();

        /** @var GetPlayerPrediction $getPlayerPrediction */
        $getPlayerPrediction = self::getContainer()->get(GetPlayerPrediction::class);

        self::assertNull($getPlayerPrediction->forPuzzle(PlayerFixture::PLAYER_REGULAR, CompetitionApiFixture::PUZZLE_HIDDEN_IMAGE));

        $priorOnly = $getPlayerPrediction->forPuzzle(PlayerFixture::PLAYER_REGULAR, CompetitionApiFixture::PUZZLE_HIDDEN_IMAGE, allowPriorOnly: true);
        self::assertNotNull($priorOnly);
        self::assertSame(0, $priorOnly->kIndices);
        self::assertNotNull($priorOnly->priorLog);
    }

    /**
     * The acceptance measurement runs on the fixtures (too few solves for a real fit: every part falls back to its
     * default) and reports both kinds.
     */
    public function testTheEvaluationRunsOnATimeSplit(): void
    {
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);

        $result = self::getContainer()->get(PredictionEvaluator::class)->evaluate(new DateTimeImmutable($clock->now()->modify('-15 days')->format('Y-m-d')));

        self::assertSame(1.0, $result['parameters']['tau'], 'Too few samples: the default');
        self::assertNotSame([], $result['first_try']);
        self::assertSame('attempts after the cut-off', $result['first_try'][0]['group']);
    }

    /**
     * :now of StatisticalInputsSql::NOT_SECRET - the app's clock
     */
    private static function clockNow(\Symfony\Component\DependencyInjection\ContainerInterface $container): string
    {
        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);

        return $clock->now()->format('Y-m-d H:i:s');
    }
}
