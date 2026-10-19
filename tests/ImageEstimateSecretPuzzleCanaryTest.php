<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ImageEstimateEvaluation;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Message\EvaluateImageEstimates;
use SpeedPuzzling\Web\Message\FitPredictionModel;
use SpeedPuzzling\Web\Message\RecordPuzzleDifficultyEstimates;
use SpeedPuzzling\Web\Query\GetImageEstimateEvaluationRows;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Query\GetPuzzleDifficultyEstimates;
use SpeedPuzzling\Web\Query\GetTimesAwaitingImagePredictions;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionReconstructor;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleDifficultyEstimateInput;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Secret puzzles never leak through the image estimates (Insights README §6, plan §5.8): a puzzle whose picture or
 * whole page is hidden (hide_image_until / hide_until ahead) has an estimate stored - from before it was hidden - and
 * an image row of a solve made then, yet no reader ever gets either: not the estimates' read side, not the prior (live,
 * reconstructed, the fit's stored blend prior), not the backfill's work list, not the evaluation.
 */
final class ImageEstimateSecretPuzzleCanaryTest extends KernelTestCase
{
    /** Whole puzzle hidden / only its picture hidden */
    private const array SECRET = [PuzzleFixture::PUZZLE_500_05 => 'hide_until', PuzzleFixture::PUZZLE_500_04 => 'hide_image_until'];

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
        $this->messageBus->dispatch(new FitPredictionModel());

        foreach (array_keys(self::SECRET) as $puzzleId) {
            $image = 'box-' . $puzzleId . '.jpg';
            $this->database->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => $image, 'id' => $puzzleId]);
            $this->messageBus->dispatch(new RecordPuzzleDifficultyEstimates([
                new PuzzleDifficultyEstimateInput($puzzleId, 'ridge-siglip2-dinov2', '1.0.0', 0.3, 0.1, 0.55, true, 4979, PuzzleImageFingerprint::of($image), null),
            ]));
        }

        // A first try made while the puzzle was public: an image row resting on its estimate
        $this->addSoloTime(PuzzleFixture::PUZZLE_500_05, null);
        /** @var int|string $imageRows */
        $imageRows = $this->database->fetchOne("SELECT COUNT(*) FROM solving_time_prediction WHERE predictor = 'image' AND image_model IS NOT NULL");
        self::assertSame(1, (int) $imageRows);

        foreach (self::SECRET as $puzzleId => $column) {
            $this->database->executeStatement("UPDATE puzzle SET {$column} = NOW() + INTERVAL '30 days' WHERE id = :id", ['id' => $puzzleId]);
        }

        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    public function testNoReaderGetsTheEstimateOfASecretPuzzle(): void
    {
        $container = self::getContainer();

        // The read side
        self::assertSame([], $container->get(GetPuzzleDifficultyEstimates::class)->forPuzzles(array_keys(self::SECRET)));

        // Live: no image prior, no image prediction
        $live = $container->get(GetPlayerPrediction::class)->sideBySide(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04);
        self::assertTrue($live->firstTry);
        self::assertNull($live->imageEstimate);
        self::assertNull($live->image);
        self::assertNull($live->blend);

        $liveTime = $this->addSoloTime(PuzzleFixture::PUZZLE_500_04, null);
        /** @var array{predictable: bool, image_model: null|string} $liveRow */
        $liveRow = $this->database->fetchAssociative("SELECT predictable, image_model FROM solving_time_prediction WHERE time_id = :id AND predictor = 'image'", ['id' => $liveTime]);
        self::assertFalse($liveRow['predictable']);
        self::assertNull($liveRow['image_model']);

        // Reconstructed: no image prediction, and the backfill does not wait for one
        $backDated = $this->addSoloTime(PuzzleFixture::PUZZLE_500_04, $this->now()->modify('-5 days')->setTime(0, 0), userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID);
        self::assertNull($container->get(PredictionReconstructor::class)->reconstructSideBySide(PlayerFixture::PLAYER_WITH_STRIPE, [$backDated])[$backDated]->imageEstimate);

        foreach ([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE] as $playerId) {
            $this->messageBus->dispatch(new BackfillSolvingTimePredictions($playerId, recomputeImage: true));
            self::assertSame([], $container->get(GetTimesAwaitingImagePredictions::class)->ofPlayer($playerId, recomputeImage: true), $playerId);
        }

        /** @var int|string $imageRowsOfSecretPuzzles */
        $imageRowsOfSecretPuzzles = $this->database->fetchOne(
            "SELECT COUNT(*) FROM solving_time_prediction r JOIN puzzle_solving_time pst ON pst.id = r.time_id
            WHERE r.predictor IN ('image', 'blend') AND r.image_model IS NOT NULL AND pst.puzzle_id IN (:a, :b)",
            ['a' => PuzzleFixture::PUZZLE_500_05, 'b' => PuzzleFixture::PUZZLE_500_04],
        );
        self::assertSame(2, (int) $imageRowsOfSecretPuzzles, 'Only the two rows of the solve made while it was public');

        // The fit's stored priors
        $this->messageBus->dispatch(new FitPredictionModel());
        /** @var int|string $storedPriors */
        $storedPriors = $this->database->fetchOne(
            'SELECT COUNT(*) FROM difficulty_prior_puzzle WHERE puzzle_id IN (:a, :b) AND (prior_log IS NOT NULL OR blend_prior_log IS NOT NULL)',
            ['a' => PuzzleFixture::PUZZLE_500_05, 'b' => PuzzleFixture::PUZZLE_500_04],
        );
        self::assertSame(0, (int) $storedPriors);
        /** @var int|string $copiedPriors */
        $copiedPriors = $this->database->fetchOne(
            'SELECT COUNT(*) FROM puzzle_difficulty WHERE puzzle_id IN (:a, :b) AND blend_prior_log IS NOT NULL',
            ['a' => PuzzleFixture::PUZZLE_500_05, 'b' => PuzzleFixture::PUZZLE_500_04],
        );
        self::assertSame(0, (int) $copiedPriors);

        // The evaluation: the rows from before it was hidden stay out
        self::assertSame([], iterator_to_array($container->get(GetImageEstimateEvaluationRows::class)->rows(new DateTimeImmutable('-10 years'), 4), false));
        $evaluation = $this->messageBus->dispatch(new EvaluateImageEstimates(120))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(ImageEstimateEvaluation::class, $evaluation);
        self::assertSame(0, $evaluation->overall['rows']);
    }

    private function addSoloTime(string $puzzleId, null|DateTimeImmutable $finishedAt, string $userId = PlayerFixture::PLAYER_REGULAR_USER_ID): string
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: null,
            time: '00:45:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: $finishedAt,
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    private function now(): DateTimeImmutable
    {
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);

        return $clock->now();
    }
}
