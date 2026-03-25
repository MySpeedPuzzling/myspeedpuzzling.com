<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;

/**
 * Creates additional puzzles and solving times for testing puzzle intelligence calculations.
 *
 * Creates 2 additional 500pc puzzles (INTEL_PUZZLE_A, INTEL_PUZZLE_B) to avoid
 * interfering with merge test puzzles (500_04, 500_05).
 *
 * After this fixture, each of the 5 players has 5+ solo first-attempt 500pc solves
 * on distinct puzzles, meeting the minimum threshold for a baseline.
 */
final class PuzzleIntelligenceFixture extends Fixture implements DependentFixtureInterface
{
    public const string INTEL_PUZZLE_A = '018d0008-0000-0000-0000-000000000001';
    public const string INTEL_PUZZLE_B = '018d0008-0000-0000-0000-000000000002';

    public const string INTEL_TIME_01 = '018d0007-0000-0000-0000-000000000001';
    public const string INTEL_TIME_02 = '018d0007-0000-0000-0000-000000000002';
    public const string INTEL_TIME_03 = '018d0007-0000-0000-0000-000000000003';
    public const string INTEL_TIME_04 = '018d0007-0000-0000-0000-000000000004';
    public const string INTEL_TIME_05 = '018d0007-0000-0000-0000-000000000005';
    public const string INTEL_TIME_06 = '018d0007-0000-0000-0000-000000000006';
    public const string INTEL_TIME_07 = '018d0007-0000-0000-0000-000000000007';
    public const string INTEL_TIME_08 = '018d0007-0000-0000-0000-000000000008';
    public const string INTEL_TIME_09 = '018d0007-0000-0000-0000-000000000009';
    public const string INTEL_TIME_10 = '018d0007-0000-0000-0000-000000000010';
    public const string INTEL_TIME_11 = '018d0007-0000-0000-0000-000000000011';
    public const string INTEL_TIME_12 = '018d0007-0000-0000-0000-000000000012';
    public const string INTEL_TIME_13 = '018d0007-0000-0000-0000-000000000013';
    public const string INTEL_TIME_14 = '018d0007-0000-0000-0000-000000000014';
    public const string INTEL_TIME_15 = '018d0007-0000-0000-0000-000000000015';
    public const string INTEL_TIME_16 = '018d0007-0000-0000-0000-000000000016';
    public const string INTEL_TIME_17 = '018d0007-0000-0000-0000-000000000017';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $player1 = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $player2 = $this->getReference(PlayerFixture::PLAYER_PRIVATE, Player::class);
        $player3 = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $player4 = $this->getReference(PlayerFixture::PLAYER_WITH_FAVORITES, Player::class);
        $player5 = $this->getReference(PlayerFixture::PLAYER_WITH_STRIPE, Player::class);

        $ravensburger = $this->getReference(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, Manufacturer::class);

        // Create 2 additional 500pc puzzles for intelligence testing
        // (avoids 500_04/500_05 which are reserved for merge testing)
        $puzzleA = new Puzzle(
            id: Uuid::fromString(self::INTEL_PUZZLE_A),
            piecesCount: 500,
            name: 'Intel Test Puzzle A',
            approved: true,
            manufacturer: $ravensburger,
        );
        $manager->persist($puzzleA);

        $puzzleB = new Puzzle(
            id: Uuid::fromString(self::INTEL_PUZZLE_B),
            piecesCount: 500,
            name: 'Intel Test Puzzle B',
            approved: true,
            manufacturer: $ravensburger,
        );
        $manager->persist($puzzleB);

        // Existing 500pc puzzles safe to use
        $puzzle500_01 = $this->getReference(PuzzleFixture::PUZZLE_500_01, Puzzle::class);
        $puzzle500_02 = $this->getReference(PuzzleFixture::PUZZLE_500_02, Puzzle::class);
        $puzzle500_03 = $this->getReference(PuzzleFixture::PUZZLE_500_03, Puzzle::class);

        // 300pc puzzle for cross-piece-count testing
        $puzzle300 = $this->getReference(PuzzleFixture::PUZZLE_300, Puzzle::class);

        // Fill gaps so every player has 5+ distinct 500pc first-attempt solo solves.
        //
        // Existing first-attempt solo 500pc solves per player:
        // Player1: 500_01(1800), 500_02(2200), 500_03(1950) = 3 puzzles
        // Player2: 500_01(1500), 500_03(2100) = 2 puzzles (500_02 is new below)
        // Player3: 500_01(2400), 500_03(1800) = 2 puzzles (500_02 is new below)
        // Player4: 500_01(3000), 500_02(1350) = 2 puzzles
        // Player5: 500_01(2100), 500_03(3200) = 2 puzzles (500_02 is new below)
        //
        // We add: puzzleA + puzzleB for all, plus 500_02 for those missing it, plus 500_03 where needed.

        $times = [
            // Player2: add 500_02, puzzleA, puzzleB (→ 5 total)
            ['id' => self::INTEL_TIME_01, 'player' => $player2, 'puzzle' => $puzzle500_02, 'seconds' => 1650, 'daysAgo' => 40, 'first' => true],
            ['id' => self::INTEL_TIME_02, 'player' => $player2, 'puzzle' => $puzzleA, 'seconds' => 1580, 'daysAgo' => 35, 'first' => true],
            ['id' => self::INTEL_TIME_03, 'player' => $player2, 'puzzle' => $puzzleB, 'seconds' => 1720, 'daysAgo' => 30, 'first' => true],

            // Player3: add 500_02, puzzleA, puzzleB (→ 5 total)
            ['id' => self::INTEL_TIME_04, 'player' => $player3, 'puzzle' => $puzzle500_02, 'seconds' => 2100, 'daysAgo' => 38, 'first' => true],
            ['id' => self::INTEL_TIME_05, 'player' => $player3, 'puzzle' => $puzzleA, 'seconds' => 2300, 'daysAgo' => 32, 'first' => true],
            ['id' => self::INTEL_TIME_06, 'player' => $player3, 'puzzle' => $puzzleB, 'seconds' => 2050, 'daysAgo' => 28, 'first' => true],

            // Player4: add 500_03, puzzleA, puzzleB (→ 5 total)
            ['id' => self::INTEL_TIME_07, 'player' => $player4, 'puzzle' => $puzzle500_03, 'seconds' => 2800, 'daysAgo' => 45, 'first' => true],
            ['id' => self::INTEL_TIME_08, 'player' => $player4, 'puzzle' => $puzzleA, 'seconds' => 2950, 'daysAgo' => 42, 'first' => true],
            ['id' => self::INTEL_TIME_09, 'player' => $player4, 'puzzle' => $puzzleB, 'seconds' => 3100, 'daysAgo' => 39, 'first' => true],

            // Player5: add 500_02, puzzleA, puzzleB (→ 5 total)
            ['id' => self::INTEL_TIME_10, 'player' => $player5, 'puzzle' => $puzzle500_02, 'seconds' => 2000, 'daysAgo' => 37, 'first' => true],
            ['id' => self::INTEL_TIME_11, 'player' => $player5, 'puzzle' => $puzzleA, 'seconds' => 2150, 'daysAgo' => 33, 'first' => true],
            ['id' => self::INTEL_TIME_12, 'player' => $player5, 'puzzle' => $puzzleB, 'seconds' => 2250, 'daysAgo' => 29, 'first' => true],

            // Player1: add puzzleA, puzzleB (→ 5 total)
            ['id' => self::INTEL_TIME_13, 'player' => $player1, 'puzzle' => $puzzleA, 'seconds' => 1900, 'daysAgo' => 36, 'first' => true],
            ['id' => self::INTEL_TIME_14, 'player' => $player1, 'puzzle' => $puzzleB, 'seconds' => 1850, 'daysAgo' => 31, 'first' => true],

            // 300pc solves for cross-piece-count testing
            ['id' => self::INTEL_TIME_15, 'player' => $player1, 'puzzle' => $puzzle300, 'seconds' => 800, 'daysAgo' => 28, 'first' => true],
            ['id' => self::INTEL_TIME_16, 'player' => $player2, 'puzzle' => $puzzle300, 'seconds' => 900, 'daysAgo' => 26, 'first' => true],

            // Repeat solve for memorability testing
            ['id' => self::INTEL_TIME_17, 'player' => $player1, 'puzzle' => $puzzle500_03, 'seconds' => 1700, 'daysAgo' => 5, 'first' => false],
        ];

        foreach ($times as $timeData) {
            $time = $this->createPuzzleSolvingTime(
                id: $timeData['id'],
                player: $timeData['player'],
                puzzle: $timeData['puzzle'],
                secondsToSolve: $timeData['seconds'],
                daysAgo: $timeData['daysAgo'],
                firstAttempt: $timeData['first'],
            );
            $manager->persist($time);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PuzzleSolvingTimeFixture::class,
            ManufacturerFixture::class,
        ];
    }

    private function createPuzzleSolvingTime(
        string $id,
        Player $player,
        Puzzle $puzzle,
        int $secondsToSolve,
        int $daysAgo,
        bool $firstAttempt,
    ): PuzzleSolvingTime {
        $now = $this->clock->now();
        $trackedAt = $now->modify("-{$daysAgo} days");

        return new PuzzleSolvingTime(
            id: Uuid::fromString($id),
            secondsToSolve: $secondsToSolve,
            player: $player,
            puzzle: $puzzle,
            trackedAt: $trackedAt,
            verified: true,
            team: null,
            finishedAt: $trackedAt,
            comment: null,
            finishedPuzzlePhoto: null,
            firstAttempt: $firstAttempt,
            unboxed: false,
        );
    }
}
