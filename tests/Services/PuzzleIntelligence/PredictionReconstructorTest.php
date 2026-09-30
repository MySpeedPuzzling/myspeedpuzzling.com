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
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionReconstructor;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleIntelligenceFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Value\SolvingTimePrediction;
use SpeedPuzzling\Web\Value\TimePredictionSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * PredictionReconstructor = today's model fed only with the data before a solve. Two kinds of guards:
 * reconstructing "as of now" must give exactly what the live model (GetPlayerPrediction on freshly
 * recalculated tables) gives - one model, not two - and nothing after a solve may change its
 * reconstruction.
 */
final class PredictionReconstructorTest extends KernelTestCase
{
    private PredictionReconstructor $reconstructor;
    private EntityManagerInterface $entityManager;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();

        $this->reconstructor = $container->get(PredictionReconstructor::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->database = $container->get(Connection::class);
    }

    /**
     * A 4th solve of PUZZLE_500_02 by PLAYER_REGULAR right now: the reconstruction equals the live
     * personal prediction. The monthly global-ratio snapshot is the one documented approximation, so it
     * is pinned to the current table for this comparison.
     */
    public function testPersonalReconstructionAsOfNowEqualsTheLiveModel(): void
    {
        $live = $this->livePrediction(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_02);
        self::assertNotNull($live);
        self::assertTrue($live->isPersonalized);

        $this->pinGlobalRatioSnapshotToTheCurrentTable();
        $timeId = $this->persistSoloTime(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_02, 1500, $this->now());

        self::assertEquals($live, $this->reconstructed(PlayerFixture::PLAYER_REGULAR, $timeId)->result);
    }

    /**
     * PLAYER_REGULAR's first solve of PUZZLE_500_04 right now: baseline × difficulty, both rebuilt from
     * raw solves, equal the live tables. Five other solvers make the puzzle scorable - only four other
     * fixture players have a 500-piece baseline, so a fifth gets one.
     */
    public function testStatisticalReconstructionAsOfNowEqualsTheLiveModel(): void
    {
        $this->makeFourthFiveHundredPiecePuzzleScorable();

        $live = $this->livePrediction(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04);
        self::assertNotNull($live);
        self::assertFalse($live->isPersonalized);

        $timeId = $this->persistSoloTime(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, 2000, $this->now());

        $reconstructed = $this->reconstructed(PlayerFixture::PLAYER_REGULAR, $timeId)->result;
        self::assertNotNull($reconstructed);
        self::assertSame($live->predictedSeconds, $reconstructed->predictedSeconds);
        self::assertSame($live->rangeLowSeconds, $reconstructed->rangeLowSeconds);
        self::assertSame($live->rangeHighSeconds, $reconstructed->rangeHighSeconds);
        self::assertFalse($reconstructed->isPersonalized);
    }

    /**
     * TIME_07 is PLAYER_REGULAR's 2nd of three PUZZLE_500_02 solves: its reconstruction knows TIME_06
     * only, and much faster solves added afterwards - of that puzzle and of others - change nothing.
     */
    public function testNothingAfterTheSolveChangesItsReconstruction(): void
    {
        $before = $this->reconstructed(PlayerFixture::PLAYER_REGULAR, PuzzleSolvingTimeFixture::TIME_07);
        self::assertNotNull($before->result);
        self::assertTrue($before->result->isPersonalized);
        self::assertSame(1, $before->result->personalSolveCount);
        self::assertSame(2200, $before->result->lastTimeSeconds);
        self::assertSame(TimePredictionSource::Reconstructed, $before->source);

        foreach ([PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_03] as $puzzleId) {
            $this->persistSoloTime(PlayerFixture::PLAYER_REGULAR, $puzzleId, 700, $this->now()->modify('-12 days'));
        }

        self::assertEquals($before, $this->reconstructed(PlayerFixture::PLAYER_REGULAR, PuzzleSolvingTimeFixture::TIME_07));
    }

    /**
     * The same for a statistical reconstruction (TIME_06, PLAYER_REGULAR's first PUZZLE_500_02 solve,
     * 20 days ago): later solves of the player's own and later first attempts of others stay out.
     */
    public function testNothingAfterAFirstSolveChangesItsStatisticalReconstruction(): void
    {
        $before = $this->reconstructed(PlayerFixture::PLAYER_REGULAR, PuzzleSolvingTimeFixture::TIME_06);

        $this->persistSoloTime(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, 700, $this->now()->modify('-18 days'));
        $this->persistSoloTime(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_05, 700, $this->now()->modify('-18 days'));
        $this->persistSoloTime($this->playerWith500PieceBaseline(1500), PuzzleFixture::PUZZLE_500_02, 600, $this->now()->modify('-2 days'));

        self::assertEquals($before, $this->reconstructed(PlayerFixture::PLAYER_REGULAR, PuzzleSolvingTimeFixture::TIME_06));
    }

    /**
     * A solve flagged first_attempt is the preferred first attempt of its puzzle - but only from the
     * day it happened. A player with five 500-piece first attempts 2 months ago, predicted on
     * INTEL_PUZZLE_A yesterday: flagging much faster re-solves of those puzzles today must not reach
     * back into yesterday's baseline.
     */
    public function testALaterSolveFlaggedFirstAttemptDoesNotReachBack(): void
    {
        $playerId = $this->newPlayer();

        foreach ([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03, PuzzleFixture::PUZZLE_500_04, PuzzleFixture::PUZZLE_500_05] as $index => $puzzleId) {
            $this->persistSoloTime($playerId, $puzzleId, 2000 + 100 * $index, $this->now()->modify('-' . (60 - $index) . ' days'));
        }

        $targetId = $this->persistSoloTime($playerId, PuzzleIntelligenceFixture::INTEL_PUZZLE_A, 2100, $this->now()->modify('-1 day'));

        $before = $this->reconstructed($playerId, $targetId);
        self::assertNotNull($before->result, 'five first attempts and a scored puzzle');
        self::assertFalse($before->result->isPersonalized);

        // Three of the five: enough to move the (weighted) median of the baseline if they leaked in
        foreach ([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03] as $puzzleId) {
            $this->persistSoloTime($playerId, $puzzleId, 600, $this->now(), firstAttempt: true);
        }

        self::assertEquals($before, $this->reconstructed($playerId, $targetId));
    }

    /**
     * Same-day solves share finished_at (a date), tracked_at orders them: PLAYER_ADMIN's 13:00 solve of
     * PUZZLE_1000_01 knows the 21-day-old one and the one from 09:00, not the one from 18:00.
     */
    public function testSameDaySolvesAreOrderedByTrackedAt(): void
    {
        $prediction = $this->reconstructed(PlayerFixture::PLAYER_ADMIN, PuzzleSolvingTimeFixture::TIME_48_SAME_DAY_MEDIUM)->result;

        self::assertNotNull($prediction);
        self::assertTrue($prediction->isPersonalized);
        self::assertSame(2, $prediction->personalSolveCount);
        self::assertSame(5200, $prediction->lastTimeSeconds);
    }

    /**
     * A player's very first 500-piece solve has neither an earlier attempt nor a baseline.
     */
    public function testTheFirstSolveOfAPieceCountCannotBePredicted(): void
    {
        $playerId = $this->newPlayer();
        $timeId = $this->persistSoloTime($playerId, PuzzleFixture::PUZZLE_500_01, 2000, $this->now()->modify('-3 days'));

        $prediction = $this->reconstructed($playerId, $timeId);

        self::assertFalse($prediction->isPredictable());
        self::assertSame(TimePredictionSource::Reconstructed, $prediction->source);
    }

    /**
     * Unboxed and suspicious times are predicted too - they only never count as inputs.
     */
    public function testAnUnboxedTimeIsPredictedButIsNoInput(): void
    {
        // TIME_45_UNBOXED: PLAYER_WITH_STRIPE, PUZZLE_500_02
        $unboxed = $this->reconstructed(PlayerFixture::PLAYER_WITH_STRIPE, PuzzleSolvingTimeFixture::TIME_45_UNBOXED);
        self::assertTrue($unboxed->isPredictable());

        $nextId = $this->persistSoloTime(PlayerFixture::PLAYER_WITH_STRIPE, PuzzleFixture::PUZZLE_500_02, 1900, $this->now());
        $next = $this->reconstructed(PlayerFixture::PLAYER_WITH_STRIPE, $nextId)->result;

        self::assertNotNull($next);
        self::assertTrue($next->isPersonalized);
        // INTEL_TIME_10 (2000 s) is the only qualifying earlier solve - the unboxed one does not count
        self::assertSame(1, $next->personalSolveCount);
        self::assertSame(2000, $next->lastTimeSeconds);
    }

    public function testIdsThatAreNotSoloTimesWithSecondsAreLeftOut(): void
    {
        $predictions = $this->reconstructor->reconstruct(PlayerFixture::PLAYER_REGULAR, [
            PuzzleSolvingTimeFixture::TIME_12, // team
            PuzzleSolvingTimeFixture::TIME_46_RELAX_NO_FINISHED_AT, // no seconds
            PuzzleSolvingTimeFixture::TIME_02, // someone else's
        ]);

        self::assertSame([], $predictions);
    }

    private function reconstructed(string $playerId, string $timeId): SolvingTimePrediction
    {
        $predictions = $this->reconstructor->reconstruct($playerId, [$timeId]);
        self::assertArrayHasKey($timeId, $predictions);

        return $predictions[$timeId];
    }

    private function livePrediction(string $playerId, string $puzzleId): null|TimePredictionResult
    {
        /** @var GetPlayerPrediction $getPlayerPrediction */
        $getPlayerPrediction = self::getContainer()->get(GetPlayerPrediction::class);

        return $getPlayerPrediction->forPuzzle($playerId, $puzzleId);
    }

    private function pinGlobalRatioSnapshotToTheCurrentTable(): void
    {
        /** @var list<array{pieces_count: int, from_attempt: int, gap_bucket: string, median_ratio: float}> $rows */
        $rows = $this->database->fetchAllAssociative('SELECT pieces_count, from_attempt, gap_bucket, median_ratio FROM global_improvement_ratio');

        $snapshot = [];

        foreach ($rows as $row) {
            $snapshot[$row['pieces_count']][$row['from_attempt']][$row['gap_bucket']] = (float) $row['median_ratio'];
        }

        /** @var CacheInterface $cache */
        $cache = self::getContainer()->get('global_improvement_ratio_snapshot_cache');
        $cache->get('global_ratios_before_' . $this->now()->format('Y-m'), static fn (): array => $snapshot);
    }

    private function makeFourthFiveHundredPiecePuzzleScorable(): void
    {
        foreach (
            [
                PlayerFixture::PLAYER_PRIVATE => 1650,
                PlayerFixture::PLAYER_ADMIN => 2050,
                PlayerFixture::PLAYER_WITH_FAVORITES => 2950,
                PlayerFixture::PLAYER_WITH_STRIPE => 2100,
                $this->playerWith500PieceBaseline(2400) => 2300,
            ] as $playerId => $seconds
        ) {
            $this->persistSoloTime($playerId, PuzzleFixture::PUZZLE_500_04, $seconds, $this->now()->modify('-30 days'), firstAttempt: true);
        }
    }

    private function playerWith500PieceBaseline(int $baselineSeconds): string
    {
        $playerId = $this->newPlayer();

        $this->database->executeStatement(
            "INSERT INTO player_baseline (id, player_id, pieces_count, baseline_seconds, qualifying_solves_count, baseline_type, computed_at) VALUES (:id, :playerId, 500, :baseline, 5, 'direct', NOW())",
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'baseline' => $baselineSeconds],
        );

        return $playerId;
    }

    private function newPlayer(): string
    {
        $player = new Player(Uuid::uuid7(), 'rec' . bin2hex(random_bytes(3)), null, null, $this->now());
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player->id->toString();
    }

    private function persistSoloTime(string $playerId, string $puzzleId, int $seconds, DateTimeImmutable $solvedAt, bool $firstAttempt = false): string
    {
        $player = $this->entityManager->find(Player::class, $playerId);
        self::assertNotNull($player);
        $puzzle = $this->entityManager->find(Puzzle::class, $puzzleId);
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
            firstAttempt: $firstAttempt,
            unboxed: false,
        );

        $this->entityManager->persist($time);
        $this->entityManager->flush();

        return $time->id->toString();
    }

    private function now(): DateTimeImmutable
    {
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);

        return $clock->now();
    }
}
