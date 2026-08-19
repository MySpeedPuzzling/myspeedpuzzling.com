<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\PuzzleStatistics;
use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeDeleted;
use SpeedPuzzling\Web\MessageHandler\RecalculatePuzzleStatisticsOnSolvingTimeChange;
use SpeedPuzzling\Web\Repository\PuzzleStatisticsRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The precomputed puzzle_statistics row, recomputed on every solving-time
 * change. Average and median are both over each player's BEST time per
 * discipline (the player_best_per_type population in
 * PuzzleStatisticsCalculator) - one value per player, however many times
 * they solved the puzzle - so "n solves" below means n distinct players.
 *
 * Persisting or removing a PuzzleSolvingTime already dispatches the event
 * synchronously from postFlush (the path AddPuzzleSolvingTime and the delete
 * handler take, with every other PuzzleSolved handler alongside); the tests
 * then invoke the handler directly, by name, and flush - what the messenger
 * transaction middleware does for it in production.
 */
final class RecalculatePuzzleStatisticsOnSolvingTimeChangeTest extends KernelTestCase
{
    private RecalculatePuzzleStatisticsOnSolvingTimeChange $handler;
    private EntityManagerInterface $entityManager;
    private PuzzleStatisticsRepository $statisticsRepository;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->handler = $container->get(RecalculatePuzzleStatisticsOnSolvingTimeChange::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->statisticsRepository = $container->get(PuzzleStatisticsRepository::class);
        $this->clock = $container->get(ClockInterface::class);
    }

    public function testOddNumberOfSoloSolvesMedianIsTheMiddleValue(): void
    {
        // five players, one solo solve each: sorted 1200 1800 2400 3000 4000
        $this->seedSoloSolves(PuzzleFixture::PUZZLE_5000, [
            PlayerFixture::PLAYER_REGULAR => 3000,
            PlayerFixture::PLAYER_PRIVATE => 1200,
            PlayerFixture::PLAYER_ADMIN => 2400,
            PlayerFixture::PLAYER_WITH_FAVORITES => 1800,
            PlayerFixture::PLAYER_WITH_STRIPE => 4000,
        ]);

        $statistics = $this->recalculatedStatistics(PuzzleFixture::PUZZLE_5000);

        self::assertSame(5, $statistics->solvedTimesSoloCount);
        self::assertSame(2400, $statistics->medianTimeSolo);
        self::assertSame(2480, $statistics->averageTimeSolo);
        self::assertSame(1200, $statistics->fastestTimeSolo);
        self::assertSame(4000, $statistics->slowestTimeSolo);

        // only solo solves: the overall median is the solo one, the other disciplines stay null
        self::assertSame(2400, $statistics->medianTime);
        self::assertNull($statistics->medianTimeDuo);
        self::assertNull($statistics->medianTimeTeam);
    }

    public function testEvenNumberOfSoloSolvesMedianIsTheRoundedMeanOfTheTwoMiddleValues(): void
    {
        // four players: sorted 1200 2000 3501 5000 -> (2000 + 3501) / 2 = 2750.5 -> 2751
        $this->seedSoloSolves(PuzzleFixture::PUZZLE_6000, [
            PlayerFixture::PLAYER_REGULAR => 5000,
            PlayerFixture::PLAYER_PRIVATE => 2000,
            PlayerFixture::PLAYER_ADMIN => 1200,
            PlayerFixture::PLAYER_WITH_FAVORITES => 3501,
        ]);

        $statistics = $this->recalculatedStatistics(PuzzleFixture::PUZZLE_6000);

        self::assertSame(4, $statistics->solvedTimesSoloCount);
        self::assertSame(2751, $statistics->medianTimeSolo);
        self::assertSame(2751, $statistics->medianTime);
        self::assertSame(2925, $statistics->averageTimeSolo);
    }

    public function testMedianIsOverEachPlayersBestTimeLikeTheAverage(): void
    {
        // the same three players as the average uses - a repeat solve does not add a value,
        // it can only lower that player's best: 3000 / 1200 / 2400 -> the 3000-player improves to 1000
        $this->seedSoloSolves(PuzzleFixture::PUZZLE_5000, [
            PlayerFixture::PLAYER_REGULAR => 3000,
            PlayerFixture::PLAYER_PRIVATE => 1200,
            PlayerFixture::PLAYER_ADMIN => 2400,
        ]);
        $this->seedSoloSolves(PuzzleFixture::PUZZLE_5000, [
            PlayerFixture::PLAYER_REGULAR => 1000,
        ]);

        $statistics = $this->recalculatedStatistics(PuzzleFixture::PUZZLE_5000);

        // 4 solves, 3 players: best times 1000 1200 2400 -> median 1200, average 1533
        self::assertSame(4, $statistics->solvedTimesSoloCount);
        self::assertSame(1200, $statistics->medianTimeSolo);
        self::assertSame(1533, $statistics->averageTimeSolo);
    }

    public function testDuoAndTeamMediansAreComputedPerDiscipline(): void
    {
        // duo: three rows by three owners 3000 4000 5000 -> 4000; team: two owners 6000 8000 -> 7000
        $this->seedGroupSolve(PuzzleFixture::PUZZLE_9000, PlayerFixture::PLAYER_REGULAR, [PlayerFixture::PLAYER_PRIVATE], 3000);
        $this->seedGroupSolve(PuzzleFixture::PUZZLE_9000, PlayerFixture::PLAYER_ADMIN, [PlayerFixture::PLAYER_WITH_FAVORITES], 5000);
        $this->seedGroupSolve(PuzzleFixture::PUZZLE_9000, PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_REGULAR], 4000);
        $this->seedGroupSolve(PuzzleFixture::PUZZLE_9000, PlayerFixture::PLAYER_REGULAR, [PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_ADMIN], 8000);
        $this->seedGroupSolve(PuzzleFixture::PUZZLE_9000, PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_ADMIN], 6000);

        $statistics = $this->recalculatedStatistics(PuzzleFixture::PUZZLE_9000);

        self::assertSame(0, $statistics->solvedTimesSoloCount);
        self::assertNull($statistics->medianTimeSolo);
        self::assertSame(3, $statistics->solvedTimesDuoCount);
        self::assertSame(4000, $statistics->medianTimeDuo);
        self::assertSame(4000, $statistics->averageTimeDuo);
        self::assertSame(2, $statistics->solvedTimesTeamCount);
        self::assertSame(7000, $statistics->medianTimeTeam);
        self::assertSame(7000, $statistics->averageTimeTeam);

        // overall: the five (player, discipline) best times 3000 4000 5000 6000 8000 -> 5000
        self::assertSame(5, $statistics->solvedTimesCount);
        self::assertSame(5000, $statistics->medianTime);
        self::assertSame(5200, $statistics->averageTime);
    }

    public function testDeletingASolveRecomputesTheMedianAndNoSolvesMeansNull(): void
    {
        $times = $this->seedSoloSolves(PuzzleFixture::PUZZLE_6000, [
            PlayerFixture::PLAYER_REGULAR => 1000,
            PlayerFixture::PLAYER_PRIVATE => 2000,
            PlayerFixture::PLAYER_ADMIN => 9000,
        ]);

        self::assertSame(2000, $this->recalculatedStatistics(PuzzleFixture::PUZZLE_6000)->medianTimeSolo);

        // removing the slowest solve (PuzzleSolvingTimeDeleted): 1000 2000 -> 1500
        $this->deleteSolve($times[PlayerFixture::PLAYER_ADMIN]);
        self::assertSame(1500, $this->statisticsOf(PuzzleFixture::PUZZLE_6000)->medianTimeSolo);

        // removing the rest: nothing to compute
        $this->deleteSolve($times[PlayerFixture::PLAYER_REGULAR]);
        $this->deleteSolve($times[PlayerFixture::PLAYER_PRIVATE]);
        $statistics = $this->statisticsOf(PuzzleFixture::PUZZLE_6000);
        self::assertSame(0, $statistics->solvedTimesCount);
        self::assertNull($statistics->medianTime);
        self::assertNull($statistics->medianTimeSolo);
        self::assertNull($statistics->averageTimeSolo);
    }

    /**
     * @param array<string, int> $secondsByPlayerId
     *
     * @return array<string, string> time id by player id
     */
    private function seedSoloSolves(string $puzzleId, array $secondsByPlayerId): array
    {
        $puzzle = $this->entityManager->find(Puzzle::class, $puzzleId);
        self::assertNotNull($puzzle);

        $timeIds = [];

        foreach ($secondsByPlayerId as $playerId => $seconds) {
            $player = $this->entityManager->find(Player::class, $playerId);
            self::assertNotNull($player);

            $time = $this->createTime($player, $puzzle, $seconds, null);
            $this->entityManager->persist($time);
            $timeIds[$playerId] = $time->id->toString();
        }

        $this->entityManager->flush();

        return $timeIds;
    }

    /**
     * @param list<string> $otherPlayerIds
     */
    private function seedGroupSolve(string $puzzleId, string $ownerId, array $otherPlayerIds, int $seconds): void
    {
        $puzzle = $this->entityManager->find(Puzzle::class, $puzzleId);
        self::assertNotNull($puzzle);
        $owner = $this->entityManager->find(Player::class, $ownerId);
        self::assertNotNull($owner);

        $puzzlers = [];

        foreach ([$ownerId, ...$otherPlayerIds] as $playerId) {
            $puzzlers[] = new Puzzler(
                playerId: $playerId,
                playerName: null,
                playerCode: null,
                playerCountry: null,
                isPrivate: false,
            );
        }

        $time = $this->createTime($owner, $puzzle, $seconds, new PuzzlersGroup(teamId: null, puzzlers: $puzzlers));
        $this->entityManager->persist($time);
        $this->entityManager->flush();
    }

    private function createTime(Player $player, Puzzle $puzzle, int $seconds, null|PuzzlersGroup $team): PuzzleSolvingTime
    {
        $now = $this->clock->now();

        return new PuzzleSolvingTime(
            id: Uuid::uuid7(),
            secondsToSolve: $seconds,
            player: $player,
            puzzle: $puzzle,
            trackedAt: $now,
            verified: true,
            team: $team,
            finishedAt: $now,
            comment: null,
            finishedPuzzlePhoto: null,
            firstAttempt: false,
            unboxed: false,
        );
    }

    private function deleteSolve(string $timeId): void
    {
        $time = $this->entityManager->find(PuzzleSolvingTime::class, $timeId);
        self::assertNotNull($time);

        $event = PuzzleSolvingTimeDeleted::fromEntity($time);
        $this->entityManager->remove($time);
        $this->entityManager->flush();

        ($this->handler)($event);
        $this->entityManager->flush();
    }

    private function recalculatedStatistics(string $puzzleId): PuzzleStatistics
    {
        // the handler only reads the puzzle id off the event
        ($this->handler)(new PuzzleSolved(Uuid::uuid7(), Uuid::fromString($puzzleId)));
        $this->entityManager->flush();

        return $this->statisticsOf($puzzleId);
    }

    private function statisticsOf(string $puzzleId): PuzzleStatistics
    {
        $this->entityManager->clear();

        $statistics = $this->statisticsRepository->findByPuzzleId(Uuid::fromString($puzzleId));
        self::assertNotNull($statistics);

        return $statistics;
    }
}
