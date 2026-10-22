<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetPlayerPuzzleTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The "My times" read model of collection pages must agree with the picker
 * card (same CTE semantics): every own solving time counts as a solve, team
 * participation counts too. Solo, pair and team times are kept apart (never
 * mixed), relax solves (no time) are counted on their own.
 */
final class GetPlayerPuzzleTimesTest extends KernelTestCase
{
    private GetPlayerPuzzleTimes $query;

    private ClockInterface $clock;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->query = $container->get(GetPlayerPuzzleTimes::class);
        $this->clock = $container->get(ClockInterface::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testRepeatedlySolvedPuzzleWhereTheLatestIsTheFastest(): void
    {
        // PLAYER_REGULAR on PUZZLE_500_02: 36:40 (20 days ago) -> 31:40 (15) -> 28:20 (10)
        $times = $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_500_02]);

        self::assertArrayHasKey(PuzzleFixture::PUZZLE_500_02, $times);
        $time = $times[PuzzleFixture::PUZZLE_500_02];

        self::assertSame(PuzzleFixture::PUZZLE_500_02, $time->puzzleId);
        self::assertSame(3, $time->solveCountAny);
        self::assertSame(3, $time->solveCountSolo);
        self::assertSame(1700, $time->fastestSeconds);
        self::assertSame(1700, $time->latestSeconds);
        self::assertFalse($time->latestDiffersFromFastest(), 'The template omits the latest line when it is the fastest result');
        self::assertNotNull($time->lastSolvedAt);
        self::assertSame(
            $this->clock->now()->modify('-10 days')->format('Y-m-d'),
            $time->lastSolvedAt->format('Y-m-d'),
        );
    }

    public function testLatestSlowerThanFastest(): void
    {
        // PLAYER_REGULAR on PUZZLE_1000_02: 3950 (16 days ago), then 4500 (4 days ago, competition
        // final) and an untimed relax row (3 days ago) that counts as a solve but never as a time
        $time = $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_1000_02])[PuzzleFixture::PUZZLE_1000_02];

        self::assertSame(3, $time->solveCountAny);
        self::assertSame(2, $time->solveCountSolo);
        self::assertSame(3950, $time->fastestSeconds);
        self::assertSame(4500, $time->latestSeconds);
        self::assertTrue($time->latestDiffersFromFastest());
        self::assertNotNull($time->lastSolvedAt);
        self::assertSame(
            $this->clock->now()->modify('-3 days')->format('Y-m-d'),
            $time->lastSolvedAt->format('Y-m-d'),
        );
    }

    public function testTeamParticipationCountsAsSolvedWithoutSoloTimes(): void
    {
        // team-002 on PUZZLE_1000_03: PLAYER_REGULAR owns the row, PLAYER_PRIVATE is only a participant
        $private = $this->query->forPuzzles(PlayerFixture::PLAYER_PRIVATE, [PuzzleFixture::PUZZLE_1000_03, PuzzleFixture::PUZZLE_1000_01]);

        self::assertSame(1, $private[PuzzleFixture::PUZZLE_1000_03]->solveCountAny);
        self::assertSame(0, $private[PuzzleFixture::PUZZLE_1000_03]->solveCountSolo);
        self::assertNull($private[PuzzleFixture::PUZZLE_1000_03]->fastestSeconds);
        self::assertNull($private[PuzzleFixture::PUZZLE_1000_03]->latestSeconds);
        self::assertNotNull($private[PuzzleFixture::PUZZLE_1000_03]->lastSolvedAt);

        // team-001 on PUZZLE_1000_01: PLAYER_PRIVATE is a participant AND has her own solo row (TIME_16)
        self::assertSame(2, $private[PuzzleFixture::PUZZLE_1000_01]->solveCountAny, 'Own solo row + team participation');
        self::assertSame(1, $private[PuzzleFixture::PUZZLE_1000_01]->solveCountSolo);
        self::assertSame(4200, $private[PuzzleFixture::PUZZLE_1000_01]->fastestSeconds);

        // The duo row PLAYER_REGULAR owns on PUZZLE_1000_01 is a solve without a solo time
        $regular = $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_1000_01])[PuzzleFixture::PUZZLE_1000_01];

        self::assertSame(1, $regular->solveCountAny);
        self::assertSame(0, $regular->solveCountSolo);
        self::assertNull($regular->fastestSeconds);
    }

    public function testPairTimesAreTheirOwnWhetherIOwnTheRowOrTookPart(): void
    {
        // team-001 on PUZZLE_1000_01 (pair, 3600 s): PLAYER_REGULAR owns the row, PLAYER_PRIVATE took part
        // and also has her own solo row (4200 s) - solo and pair stay apart
        $regular = $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_1000_01])[PuzzleFixture::PUZZLE_1000_01];

        self::assertNull($regular->fastestSeconds);
        self::assertSame(3600, $regular->fastestPairSeconds);
        self::assertNull($regular->fastestTeamSeconds);
        self::assertSame(0, $regular->relaxSolveCount);

        $private = $this->query->forPuzzles(PlayerFixture::PLAYER_PRIVATE, [PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_1000_03]);

        self::assertSame(4200, $private[PuzzleFixture::PUZZLE_1000_01]->fastestSeconds);
        self::assertSame(3600, $private[PuzzleFixture::PUZZLE_1000_01]->fastestPairSeconds);

        // team-002 on PUZZLE_1000_03 (pair, 4000 s): PLAYER_PRIVATE only took part
        self::assertNull($private[PuzzleFixture::PUZZLE_1000_03]->fastestSeconds);
        self::assertSame(4000, $private[PuzzleFixture::PUZZLE_1000_03]->fastestPairSeconds);
        self::assertNull($private[PuzzleFixture::PUZZLE_1000_03]->fastestTeamSeconds);
    }

    public function testTeamTimesAndRelaxSolvesAreKeptApartFromPairs(): void
    {
        $trio = [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_ADMIN];
        $this->seedSolve(PuzzleFixture::PUZZLE_9000, PlayerFixture::PLAYER_REGULAR, $trio, seconds: 36000);
        $this->seedSolve(PuzzleFixture::PUZZLE_9000, PlayerFixture::PLAYER_REGULAR, $trio, seconds: 33000);
        $this->seedSolve(PuzzleFixture::PUZZLE_9000, PlayerFixture::PLAYER_REGULAR, $trio, seconds: null);

        foreach ([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE] as $playerId) {
            $time = $this->query->forPuzzles($playerId, [PuzzleFixture::PUZZLE_9000])[PuzzleFixture::PUZZLE_9000];

            self::assertSame(3, $time->solveCountAny, $playerId);
            self::assertNull($time->fastestSeconds, $playerId);
            self::assertNull($time->fastestPairSeconds, $playerId);
            self::assertSame(33000, $time->fastestTeamSeconds, $playerId);
            self::assertSame(1, $time->relaxSolveCount, 'An untimed team row is a relax solve, owner or not');
        }

        // PLAYER_REGULAR on PUZZLE_1000_02: two solo times and one untimed solo row
        $regular = $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_1000_02])[PuzzleFixture::PUZZLE_1000_02];

        self::assertSame(1, $regular->relaxSolveCount);
        self::assertNull($regular->fastestPairSeconds);
        self::assertNull($regular->fastestTeamSeconds);
    }

    public function testPairTimesAwaitingVerificationAreNeverShown(): void
    {
        $this->seedSolve(
            PuzzleFixture::PUZZLE_1000_01,
            PlayerFixture::PLAYER_REGULAR,
            [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE],
            seconds: 600,
            suspicious: true,
        );

        $regular = $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_1000_01])[PuzzleFixture::PUZZLE_1000_01];

        self::assertSame(2, $regular->solveCountAny, 'It still counts as solved');
        self::assertSame(3600, $regular->fastestPairSeconds);
        self::assertSame(0, $regular->relaxSolveCount);
    }

    public function testNeverSolvedPuzzlesAreAbsentAndOnlyAskedPuzzlesAreReturned(): void
    {
        $times = $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_9000, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_02]);

        self::assertSame([PuzzleFixture::PUZZLE_500_02], array_keys($times));
        self::assertSame([], $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, []));
        self::assertSame([], $this->query->forPuzzles('00000000-0000-0000-0000-000000000099', [PuzzleFixture::PUZZLE_500_02]));
    }

    /**
     * @param non-empty-list<string> $participants
     */
    private function seedSolve(string $puzzleId, string $owner, array $participants, null|int $seconds, bool $suspicious = false): void
    {
        $puzzle = $this->entityManager->find(Puzzle::class, $puzzleId);
        $ownerPlayer = $this->entityManager->find(Player::class, $owner);
        self::assertNotNull($puzzle);
        self::assertNotNull($ownerPlayer);

        $puzzlers = [];

        foreach ($participants as $participantId) {
            $participant = $this->entityManager->find(Player::class, $participantId);
            self::assertNotNull($participant);

            $puzzlers[] = new Puzzler(
                playerId: $participant->id->toString(),
                playerName: $participant->name,
                playerCode: $participant->code,
                playerCountry: null,
                isPrivate: false,
            );
        }

        $solvedAt = TestClock::now()->modify('-1 day');

        $this->entityManager->persist(new PuzzleSolvingTime(
            id: Uuid::uuid7(),
            secondsToSolve: $seconds,
            player: $ownerPlayer,
            puzzle: $puzzle,
            trackedAt: $solvedAt,
            verified: true,
            team: new PuzzlersGroup(teamId: null, puzzlers: $puzzlers),
            finishedAt: $solvedAt,
            comment: null,
            finishedPuzzlePhoto: null,
            firstAttempt: false,
            unboxed: false,
            suspicious: $suspicious,
        ));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
