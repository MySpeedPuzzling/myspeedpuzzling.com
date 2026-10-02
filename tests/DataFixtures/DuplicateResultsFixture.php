<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;

/**
 * Results saved twice (docs/features/duplicate-results.md) - on their own players and puzzle, so no other
 * fixture's counts, statistics or leaderboards change.
 */
final class DuplicateResultsFixture extends Fixture implements DependentFixtureInterface
{
    public const string PLAYER_TWINS = '018d0021-0000-0000-0000-000000000001';
    public const string PLAYER_TWINS_TEAMMATE = '018d0021-0000-0000-0000-000000000002';
    public const string PUZZLE_TWINS = '018d0021-0000-0000-0000-000000000010';

    // Tier A: the same form sent again 7 s later, identical in every field
    public const string TIME_CERTAIN_A = '018d0021-0000-0000-0000-000000000101';
    public const string TIME_CERTAIN_B = '018d0021-0000-0000-0000-000000000102';
    // Tier B: the same solo result saved again 2 minutes later
    public const string TIME_STRONG_A = '018d0021-0000-0000-0000-000000000103';
    public const string TIME_STRONG_B = '018d0021-0000-0000-0000-000000000104';
    // Tier B: both members of a pair saved it (PLAYER_TWINS first, the teammate in the evening)
    public const string TIME_TEAMMATE_A = '018d0021-0000-0000-0000-000000000105';
    public const string TIME_TEAMMATE_B = '018d0021-0000-0000-0000-000000000106';
    // Tier C: identical twins 5 s apart, but in a practice session (another solve of the puzzle that day)
    public const string TIME_PRACTICE_A = '018d0021-0000-0000-0000-000000000107';
    public const string TIME_PRACTICE_B = '018d0021-0000-0000-0000-000000000108';
    public const string TIME_PRACTICE_OTHER = '018d0021-0000-0000-0000-000000000109';
    // Not a case: the same time on two different days, saved days apart
    public const string TIME_OTHER_DAY_A = '018d0021-0000-0000-0000-000000000110';
    public const string TIME_OTHER_DAY_B = '018d0021-0000-0000-0000-000000000111';

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly PuzzlingTeamResolver $puzzlingTeamResolver,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $twins = new Player(
            id: Uuid::fromString(self::PLAYER_TWINS),
            code: 'twins1',
            userId: 'auth0|twins001',
            name: 'Dana Twin',
            registeredAt: $this->clock->now(),
        );
        $manager->persist($twins);
        $this->addReference(self::PLAYER_TWINS, $twins);

        $teammate = new Player(
            id: Uuid::fromString(self::PLAYER_TWINS_TEAMMATE),
            code: 'twins2',
            userId: 'auth0|twins002',
            name: 'Tom Twin',
            registeredAt: $this->clock->now(),
        );
        $manager->persist($teammate);
        $this->addReference(self::PLAYER_TWINS_TEAMMATE, $teammate);

        // Not approved and an odd piece count: no catalogue list, brand page or solve-time bucket sees it
        $puzzle = new Puzzle(
            id: Uuid::fromString(self::PUZZLE_TWINS),
            piecesCount: 108,
            name: 'Twins Puzzle',
            approved: false,
            image: null,
            manufacturer: $this->getReference(ManufacturerFixture::MANUFACTURER_TREFL, Manufacturer::class),
            addedByUser: $twins,
            addedAt: $this->clock->now(),
        );
        $manager->persist($puzzle);
        $this->addReference(self::PUZZLE_TWINS, $puzzle);

        // PuzzlingTeamResolver inserts the pair right away - its members must exist by then
        $manager->flush();

        $pair = new PuzzlersGroup(
            teamId: null,
            puzzlers: [
                new Puzzler(playerId: self::PLAYER_TWINS, playerName: 'Dana Twin', playerCode: 'twins1', playerCountry: null, isPrivate: false),
                new Puzzler(playerId: self::PLAYER_TWINS_TEAMMATE, playerName: 'Tom Twin', playerCode: 'twins2', playerCountry: null, isPrivate: false),
            ],
        );
        $pairAsTrackedByTeammate = new PuzzlersGroup(
            teamId: null,
            puzzlers: array_reverse($pair->puzzlers),
        );

        $times = [
            [self::TIME_CERTAIN_A, $twins, 1111, 40, '10:00:00', null],
            [self::TIME_CERTAIN_B, $twins, 1111, 40, '10:00:07', null],
            [self::TIME_STRONG_A, $twins, 2222, 41, '10:00:00', null],
            [self::TIME_STRONG_B, $twins, 2222, 41, '10:02:00', null],
            [self::TIME_TEAMMATE_A, $twins, 3333, 42, '18:00:00', $pair],
            [self::TIME_TEAMMATE_B, $teammate, 3333, 42, '20:30:00', $pairAsTrackedByTeammate],
            [self::TIME_PRACTICE_OTHER, $twins, 500, 43, '08:30:00', null],
            [self::TIME_PRACTICE_A, $twins, 444, 43, '09:00:00', null],
            [self::TIME_PRACTICE_B, $twins, 444, 43, '09:00:05', null],
            [self::TIME_OTHER_DAY_A, $twins, 5555, 50, '12:00:00', null],
            [self::TIME_OTHER_DAY_B, $twins, 5555, 44, '12:00:00', null],
        ];

        foreach ($times as [$id, $player, $seconds, $daysAgo, $savedAt, $team]) {
            $day = $this->day($daysAgo);

            $time = new PuzzleSolvingTime(
                id: Uuid::fromString($id),
                secondsToSolve: $seconds,
                player: $player,
                puzzle: $puzzle,
                trackedAt: new DateTimeImmutable($day->format('Y-m-d') . ' ' . $savedAt),
                verified: true,
                team: $team,
                finishedAt: $day,
                comment: null,
                finishedPuzzlePhoto: null,
                firstAttempt: false,
                unboxed: false,
                puzzlingTeam: $this->puzzlingTeamResolver->resolve($team),
            );
            $manager->persist($time);
            $this->addReference($id, $time);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ManufacturerFixture::class,
        ];
    }

    private function day(int $daysAgo): DateTimeImmutable
    {
        return $this->clock->now()->modify("-{$daysAgo} days")->setTime(0, 0);
    }
}
