<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetPlayerPuzzleSolves;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The "solves" object of the public API's puzzle cards: one player's own
 * history on a list of puzzles, always split by discipline, the same row set
 * as /me/results?type= (solo rows the player owns; duo / team rows the player
 * took part in), unboxed and untimed rows included.
 */
final class GetPlayerPuzzleSolvesTest extends KernelTestCase
{
    private GetPlayerPuzzleSolves $query;

    private ClockInterface $clock;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->query = $container->get(GetPlayerPuzzleSolves::class);
        $this->clock = $container->get(ClockInterface::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testSoloSolvesCountBestAndLast(): void
    {
        // PLAYER_REGULAR on PUZZLE_500_02: 2200 (20 days ago) -> 1900 (15) -> 1700 (10)
        $solves = $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_500_02]);

        self::assertArrayHasKey(PuzzleFixture::PUZZLE_500_02, $solves);
        $solo = $solves[PuzzleFixture::PUZZLE_500_02]->solo;

        self::assertSame(3, $solo->count);
        self::assertSame(1700, $solo->bestTimeSeconds);
        self::assertSame(1700, $solo->lastTimeSeconds);
        self::assertNotNull($solo->firstSolvedAt);
        self::assertNotNull($solo->lastSolvedAt);
        self::assertSame($this->clock->now()->modify('-20 days')->format('Y-m-d'), $solo->firstSolvedAt->format('Y-m-d'));
        self::assertSame($this->clock->now()->modify('-10 days')->format('Y-m-d'), $solo->lastSolvedAt->format('Y-m-d'));

        self::assertSame(0, $solves[PuzzleFixture::PUZZLE_500_02]->duo->count);
        self::assertSame(0, $solves[PuzzleFixture::PUZZLE_500_02]->team->count);
    }

    public function testLastTimeIsTheMostRecentTimedSolveAndUntimedRowsStillCount(): void
    {
        // PLAYER_REGULAR on PUZZLE_1000_02: 3950 (16 days ago), 4500 (4 days ago) and an untimed
        // relax row (3 days ago): three solves, the last *time* is 4500, the last solve is 3 days ago
        $solo = $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_1000_02])[PuzzleFixture::PUZZLE_1000_02]->solo;

        self::assertSame(3, $solo->count);
        self::assertSame(3950, $solo->bestTimeSeconds);
        self::assertSame(4500, $solo->lastTimeSeconds);
        self::assertNotNull($solo->lastSolvedAt);
        self::assertSame($this->clock->now()->modify('-3 days')->format('Y-m-d'), $solo->lastSolvedAt->format('Y-m-d'));
    }

    public function testUnboxedSolvesAreIncluded(): void
    {
        // PLAYER_WITH_STRIPE on PUZZLE_500_02: a first-attempt solve (2000 s, 37 days ago)
        // and an unboxed one (2000 s, 2 days ago) - the player's own data, both count
        $solo = $this->query->forPuzzles(PlayerFixture::PLAYER_WITH_STRIPE, [PuzzleFixture::PUZZLE_500_02])[PuzzleFixture::PUZZLE_500_02]->solo;

        self::assertSame(2, $solo->count);
        self::assertSame(2000, $solo->bestTimeSeconds);
        self::assertNotNull($solo->lastSolvedAt);
        self::assertSame($this->clock->now()->modify('-2 days')->format('Y-m-d'), $solo->lastSolvedAt->format('Y-m-d'));
    }

    public function testDuoAndTeamAreParticipationNotOwnership(): void
    {
        // team-001 on PUZZLE_1000_01 (3600 s): PLAYER_REGULAR owns the row, PLAYER_PRIVATE is a
        // participant - a duo for both of them; PLAYER_PRIVATE also has her own solo row (4200 s)
        $regular = $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_1000_01])[PuzzleFixture::PUZZLE_1000_01];
        $private = $this->query->forPuzzles(PlayerFixture::PLAYER_PRIVATE, [PuzzleFixture::PUZZLE_1000_01])[PuzzleFixture::PUZZLE_1000_01];

        self::assertSame(0, $regular->solo->count);
        self::assertSame(1, $regular->duo->count);
        self::assertSame(3600, $regular->duo->bestTimeSeconds);
        self::assertSame(3600, $regular->duo->lastTimeSeconds);
        self::assertSame(0, $regular->team->count);

        self::assertSame(1, $private->solo->count);
        self::assertSame(4200, $private->solo->bestTimeSeconds);
        self::assertSame(1, $private->duo->count);
        self::assertSame(3600, $private->duo->bestTimeSeconds);

        // a player outside the team sees nothing of it
        $admin = $this->query->forPuzzles(PlayerFixture::PLAYER_ADMIN, [PuzzleFixture::PUZZLE_1000_01])[PuzzleFixture::PUZZLE_1000_01];
        self::assertSame(0, $admin->duo->count);
    }

    public function testTeamOfThreeIsATeamSolveForEveryMember(): void
    {
        $this->seedTeamSolve(
            PuzzleFixture::PUZZLE_9000,
            owner: PlayerFixture::PLAYER_REGULAR,
            participants: [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_ADMIN],
            seconds: 36000,
        );

        foreach ([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_ADMIN] as $playerId) {
            $solves = $this->query->forPuzzles($playerId, [PuzzleFixture::PUZZLE_9000])[PuzzleFixture::PUZZLE_9000];

            self::assertSame(0, $solves->solo->count, $playerId);
            self::assertSame(0, $solves->duo->count, $playerId);
            self::assertSame(1, $solves->team->count, $playerId);
            self::assertSame(36000, $solves->team->bestTimeSeconds, $playerId);
            self::assertNotNull($solves->team->firstSolvedAt);
            self::assertEquals($solves->team->firstSolvedAt, $solves->team->lastSolvedAt);
        }

        self::assertArrayNotHasKey(PuzzleFixture::PUZZLE_9000, $this->query->forPuzzles(PlayerFixture::PLAYER_WITH_STRIPE, [PuzzleFixture::PUZZLE_9000]));
    }

    public function testNeverSolvedPuzzlesAreAbsentAndOnlyAskedPuzzlesAreReturned(): void
    {
        $solves = $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_9000, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_02]);

        self::assertSame([PuzzleFixture::PUZZLE_500_02], array_keys($solves));
        self::assertSame([], $this->query->forPuzzles(PlayerFixture::PLAYER_REGULAR, []));
        self::assertSame([], $this->query->forPuzzles('00000000-0000-0000-0000-000000000099', [PuzzleFixture::PUZZLE_500_02]));
    }

    /**
     * @param non-empty-list<string> $participants
     */
    private function seedTeamSolve(string $puzzleId, string $owner, array $participants, int $seconds): void
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

        $now = new DateTimeImmutable('-1 day');

        $this->entityManager->persist(new PuzzleSolvingTime(
            id: Uuid::uuid7(),
            secondsToSolve: $seconds,
            player: $ownerPlayer,
            puzzle: $puzzle,
            trackedAt: $now,
            verified: true,
            team: new PuzzlersGroup(teamId: 'team-test-trio', puzzlers: $puzzlers),
            finishedAt: $now,
            comment: null,
            finishedPuzzlePhoto: null,
            firstAttempt: true,
            unboxed: false,
        ));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
