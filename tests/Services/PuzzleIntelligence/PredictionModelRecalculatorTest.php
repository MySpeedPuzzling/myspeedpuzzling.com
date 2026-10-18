<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionEvaluator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The prediction model's step of the insights batch (PredictionModelRecalculator): the daily fit, the drift and spread
 * on the pace history, the BrandPiecesPrior groups - and what predictions take from them.
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

    public function testAFullRunFitsTheModelOnceAndWritesOnlyChangedDrift(): void
    {
        $first = $this->recalculator->recalculate(refitPredictionModel: true);
        self::assertTrue($first['prediction_model_fitted']);

        $fitted = self::getContainer()->get(PredictionModelParametersProvider::class)->current();
        self::assertNotNull($fitted->fittedAt, 'The fit is stored for the current model version');

        /** @var int|string $withoutDrift */
        $withoutDrift = $this->database->fetchOne('SELECT COUNT(*) FROM player_pace_history WHERE drift IS NULL OR spread IS NULL');
        self::assertSame(0, (int) $withoutDrift, 'Every pace row carries its drift and spread');
        /** @var int|string $groups */
        $groups = $this->database->fetchOne('SELECT COUNT(*) FROM difficulty_prior_group');
        self::assertGreaterThan(0, (int) $groups);

        // The next run: no fit (one a day), nothing to write
        $second = $this->recalculator->recalculate();
        self::assertFalse($second['prediction_model_fitted']);
        self::assertSame(0, $second['drift_rows_written']);
    }

    /**
     * Secret puzzles (hide_until / hide_image_until in the future) are no training row of a prior and get none.
     */
    public function testSecretPuzzlesHaveNoPriorAndFeedNone(): void
    {
        $this->recalculator->recalculate(refitPredictionModel: true);

        /** @var int|string $secretShares */
        $secretShares = $this->database->fetchOne('
            SELECT COUNT(*) FROM difficulty_prior_puzzle dpp
            JOIN puzzle p ON p.id = dpp.puzzle_id
            WHERE p.hide_until > NOW() OR p.hide_image_until > NOW()
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
        $this->recalculator->recalculate(refitPredictionModel: true);

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
}
