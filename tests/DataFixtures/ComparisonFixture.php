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
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;

/**
 * Isolated fixtures for the player-comparison queries. Uses dedicated players and
 * reuses puzzles that no other fixture assigns solving times to (PUZZLE_3000/4000/
 * 5000/6000/9000), so each comparison subject's per-player results are fully
 * deterministic and independent of the rest of the fixture set.
 *
 * Data map:
 *   PUZZLE_3000 (3000pc, Trefl):
 *     CMP_A solo: 1000 (first try) -> 800 -> 600 (best);  CMP_B solo 700;  CMP_C solo 650
 *   PUZZLE_4000 (4000pc, Ravensburger):
 *     CMP_A solo: 900 (first try, valid) + 400 (suspicious) + null;  CMP_B solo 1200
 *   PUZZLE_5000 (5000pc, Ravensburger): duo CMP_A + CMP_B, 2000 (owner CMP_A)
 *   PUZZLE_6000 (6000pc, Trefl):        duo CMP_A + CMP_C, 1500 (owner CMP_A)
 *   CMP_TEAM_PUZZLE (dedicated):        team CMP_A + CMP_B + CMP_C, 3000 (owner CMP_A)
 */
final class ComparisonFixture extends Fixture implements DependentFixtureInterface
{
    public const string CMP_A = '018d00c0-0000-0000-0000-000000000001';
    public const string CMP_B = '018d00c0-0000-0000-0000-000000000002';
    public const string CMP_C = '018d00c0-0000-0000-0000-000000000003';
    public const string CMP_D = '018d00c0-0000-0000-0000-000000000004';

    public const string CMP_A_CODE = 'cmpa';
    public const string CMP_D_CODE = 'cmpd';

    /** Dedicated puzzle for the team solve (kept separate so no other test's "unsolved" assumptions break). */
    public const string CMP_TEAM_PUZZLE = '018d00c3-0000-0000-0000-000000000001';

    public const string T_A3_FIRST = '018d00c6-0000-0000-0000-000000000001';
    public const string T_A3_MID = '018d00c6-0000-0000-0000-000000000002';
    public const string T_A3_BEST = '018d00c6-0000-0000-0000-000000000003';
    public const string T_A4_NORMAL = '018d00c6-0000-0000-0000-000000000004';
    public const string T_A4_SUSPICIOUS = '018d00c6-0000-0000-0000-000000000005';
    public const string T_A4_NULL = '018d00c6-0000-0000-0000-000000000006';
    public const string T_B3 = '018d00c6-0000-0000-0000-000000000007';
    public const string T_B4 = '018d00c6-0000-0000-0000-000000000008';
    public const string T_C3 = '018d00c6-0000-0000-0000-000000000009';
    public const string T_DUO_AB = '018d00c6-0000-0000-0000-000000000010';
    public const string T_DUO_AC = '018d00c6-0000-0000-0000-000000000011';
    public const string T_TEAM_ABC = '018d00c6-0000-0000-0000-000000000012';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $playerA = $this->createPlayer(self::CMP_A, self::CMP_A_CODE, 'Compare A', 'cz', false);
        $playerB = $this->createPlayer(self::CMP_B, 'cmpb', 'Compare B', 'us', false);
        $playerC = $this->createPlayer(self::CMP_C, 'cmpc', 'Compare C', 'de', false);
        $playerD = $this->createPlayer(self::CMP_D, self::CMP_D_CODE, 'Compare D', 'fr', true);

        foreach ([$playerA, $playerB, $playerC, $playerD] as $player) {
            $manager->persist($player);
        }

        $puzzle3000 = $this->getReference(PuzzleFixture::PUZZLE_3000, Puzzle::class);
        $puzzle4000 = $this->getReference(PuzzleFixture::PUZZLE_4000, Puzzle::class);
        $puzzle5000 = $this->getReference(PuzzleFixture::PUZZLE_5000, Puzzle::class);
        $puzzle6000 = $this->getReference(PuzzleFixture::PUZZLE_6000, Puzzle::class);

        // Dedicated puzzle for the team solve so it does not turn an "unsolved" fixture puzzle into a solved one.
        $teamPuzzle = new Puzzle(
            id: Uuid::fromString(self::CMP_TEAM_PUZZLE),
            piecesCount: 8000,
            name: 'Comparison Team Puzzle',
            approved: true,
            manufacturer: $this->getReference(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, Manufacturer::class),
        );
        $manager->persist($teamPuzzle);

        // --- CMP_A solo on PUZZLE_3000: first try slowest, best last ---
        $manager->persist($this->solo(self::T_A3_FIRST, $playerA, $puzzle3000, 1000, 30, firstAttempt: true));
        $manager->persist($this->solo(self::T_A3_MID, $playerA, $puzzle3000, 800, 20, firstAttempt: false));
        $manager->persist($this->solo(self::T_A3_BEST, $playerA, $puzzle3000, 600, 10, firstAttempt: false));

        // --- CMP_A solo on PUZZLE_4000: a valid time + a suspicious (faster) + a null ---
        $manager->persist($this->solo(self::T_A4_NORMAL, $playerA, $puzzle4000, 900, 15, firstAttempt: true));
        $manager->persist($this->solo(self::T_A4_SUSPICIOUS, $playerA, $puzzle4000, 400, 14, firstAttempt: false, suspicious: true));
        $manager->persist($this->solo(self::T_A4_NULL, $playerA, $puzzle4000, null, 13, firstAttempt: false));

        // --- CMP_B / CMP_C solo (for ranking & union/common) ---
        $manager->persist($this->solo(self::T_B3, $playerB, $puzzle3000, 700, 12, firstAttempt: true));
        $manager->persist($this->solo(self::T_B4, $playerB, $puzzle4000, 1200, 11, firstAttempt: true));
        $manager->persist($this->solo(self::T_C3, $playerC, $puzzle3000, 650, 9, firstAttempt: true));

        // --- Duos (owner CMP_A, partner is a team member only) ---
        $manager->persist($this->grouped(self::T_DUO_AB, $playerA, $puzzle5000, 2000, 8, [$playerA, $playerB]));
        $manager->persist($this->grouped(self::T_DUO_AC, $playerA, $puzzle6000, 1500, 7, [$playerA, $playerC]));

        // --- Team of three (owner CMP_A) ---
        $manager->persist($this->grouped(self::T_TEAM_ABC, $playerA, $teamPuzzle, 3000, 6, [$playerA, $playerB, $playerC]));

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PuzzleFixture::class,
        ];
    }

    private function createPlayer(string $id, string $code, string $name, string $country, bool $isPrivate): Player
    {
        $player = new Player(
            id: Uuid::fromString($id),
            code: $code,
            userId: 'auth0|' . $code,
            email: $code . '@comparison.test',
            name: $name,
            registeredAt: $this->clock->now(),
        );

        $player->changeProfile(
            name: $name,
            email: $code . '@comparison.test',
            city: null,
            country: $country,
            avatar: null,
            bio: null,
            facebook: null,
            instagram: null,
            twitch: null,
        );

        if ($isPrivate) {
            $player->changeProfileVisibility(isPrivate: true);
        }

        return $player;
    }

    private function solo(
        string $id,
        Player $player,
        Puzzle $puzzle,
        null|int $seconds,
        int $daysAgo,
        bool $firstAttempt,
        bool $suspicious = false,
    ): PuzzleSolvingTime {
        $trackedAt = $this->clock->now()->modify("-{$daysAgo} days");

        return new PuzzleSolvingTime(
            id: Uuid::fromString($id),
            secondsToSolve: $seconds,
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
            suspicious: $suspicious,
        );
    }

    /**
     * @param non-empty-list<Player> $puzzlers first entry is the owner
     */
    private function grouped(
        string $id,
        Player $owner,
        Puzzle $puzzle,
        int $seconds,
        int $daysAgo,
        array $puzzlers,
    ): PuzzleSolvingTime {
        $trackedAt = $this->clock->now()->modify("-{$daysAgo} days");

        $group = new PuzzlersGroup(
            teamId: null,
            puzzlers: array_map(
                static fn (Player $player): Puzzler => new Puzzler(
                    playerId: $player->id->toString(),
                    playerName: $player->name,
                    playerCode: $player->code,
                    playerCountry: null,
                    isPrivate: false,
                ),
                $puzzlers,
            ),
        );

        return new PuzzleSolvingTime(
            id: Uuid::fromString($id),
            secondsToSolve: $seconds,
            player: $owner,
            puzzle: $puzzle,
            trackedAt: $trackedAt,
            verified: true,
            team: $group,
            finishedAt: $trackedAt,
            comment: null,
            finishedPuzzlePhoto: null,
            firstAttempt: true,
            unboxed: false,
        );
    }
}
