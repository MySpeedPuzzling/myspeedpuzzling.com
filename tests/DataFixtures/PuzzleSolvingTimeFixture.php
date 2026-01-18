<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;

final class PuzzleSolvingTimeFixture extends Fixture implements DependentFixtureInterface
{
    public const string TIME_01 = '018d0006-0000-0000-0000-000000000001';
    public const string TIME_02 = '018d0006-0000-0000-0000-000000000002';
    public const string TIME_03 = '018d0006-0000-0000-0000-000000000003';
    public const string TIME_04 = '018d0006-0000-0000-0000-000000000004';
    public const string TIME_05 = '018d0006-0000-0000-0000-000000000005';
    public const string TIME_06 = '018d0006-0000-0000-0000-000000000006';
    public const string TIME_07 = '018d0006-0000-0000-0000-000000000007';
    public const string TIME_08 = '018d0006-0000-0000-0000-000000000008';
    public const string TIME_09 = '018d0006-0000-0000-0000-000000000009';
    public const string TIME_10 = '018d0006-0000-0000-0000-000000000010';
    public const string TIME_11 = '018d0006-0000-0000-0000-000000000011';
    public const string TIME_12 = '018d0006-0000-0000-0000-000000000012';
    public const string TIME_13 = '018d0006-0000-0000-0000-000000000013';
    public const string TIME_14 = '018d0006-0000-0000-0000-000000000014';
    public const string TIME_15 = '018d0006-0000-0000-0000-000000000015';
    public const string TIME_16 = '018d0006-0000-0000-0000-000000000016';
    public const string TIME_17 = '018d0006-0000-0000-0000-000000000017';
    public const string TIME_18 = '018d0006-0000-0000-0000-000000000018';
    public const string TIME_19 = '018d0006-0000-0000-0000-000000000019';
    public const string TIME_20 = '018d0006-0000-0000-0000-000000000020';
    public const string TIME_21 = '018d0006-0000-0000-0000-000000000021';
    public const string TIME_22 = '018d0006-0000-0000-0000-000000000022';
    public const string TIME_23 = '018d0006-0000-0000-0000-000000000023';
    public const string TIME_24 = '018d0006-0000-0000-0000-000000000024';
    public const string TIME_25 = '018d0006-0000-0000-0000-000000000025';
    public const string TIME_26 = '018d0006-0000-0000-0000-000000000026';
    public const string TIME_27 = '018d0006-0000-0000-0000-000000000027';
    public const string TIME_28 = '018d0006-0000-0000-0000-000000000028';
    public const string TIME_29 = '018d0006-0000-0000-0000-000000000029';
    public const string TIME_30 = '018d0006-0000-0000-0000-000000000030';
    public const string TIME_31 = '018d0006-0000-0000-0000-000000000031';
    public const string TIME_32 = '018d0006-0000-0000-0000-000000000032';
    public const string TIME_33 = '018d0006-0000-0000-0000-000000000033';
    public const string TIME_34 = '018d0006-0000-0000-0000-000000000034';
    public const string TIME_35 = '018d0006-0000-0000-0000-000000000035';
    public const string TIME_36 = '018d0006-0000-0000-0000-000000000036';
    public const string TIME_37 = '018d0006-0000-0000-0000-000000000037';
    public const string TIME_38 = '018d0006-0000-0000-0000-000000000038';
    public const string TIME_39 = '018d0006-0000-0000-0000-000000000039';
    public const string TIME_40 = '018d0006-0000-0000-0000-000000000040';
    public const string TIME_41 = '018d0006-0000-0000-0000-000000000041';
    public const string TIME_42 = '018d0006-0000-0000-0000-000000000042';
    public const string TIME_43 = '018d0006-0000-0000-0000-000000000043';
    public const string TIME_44 = '018d0006-0000-0000-0000-000000000044';

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

        $puzzle500_01 = $this->getReference(PuzzleFixture::PUZZLE_500_01, Puzzle::class);
        $puzzle500_02 = $this->getReference(PuzzleFixture::PUZZLE_500_02, Puzzle::class);
        $puzzle500_03 = $this->getReference(PuzzleFixture::PUZZLE_500_03, Puzzle::class);
        $puzzle500_05 = $this->getReference(PuzzleFixture::PUZZLE_500_05, Puzzle::class);
        $puzzle1000_01 = $this->getReference(PuzzleFixture::PUZZLE_1000_01, Puzzle::class);
        $puzzle1000_02 = $this->getReference(PuzzleFixture::PUZZLE_1000_02, Puzzle::class);
        $puzzle1000_03 = $this->getReference(PuzzleFixture::PUZZLE_1000_03, Puzzle::class);
        $puzzle1500_01 = $this->getReference(PuzzleFixture::PUZZLE_1500_01, Puzzle::class);
        $puzzle1500_02 = $this->getReference(PuzzleFixture::PUZZLE_1500_02, Puzzle::class);
        $puzzle2000 = $this->getReference(PuzzleFixture::PUZZLE_2000, Puzzle::class);

        $wjpcCompetition = $this->getReference(CompetitionFixture::COMPETITION_WJPC_2024, Competition::class);
        $wjpcQualificationRound = $this->getReference(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, CompetitionRound::class);
        $wjpcFinalRound = $this->getReference(CompetitionRoundFixture::ROUND_WJPC_FINAL, CompetitionRound::class);

        // PUZZLE_500_01: Solved by 5 different players with different times - for testing avg/min/max
        $times = [
            ['id' => self::TIME_01, 'player' => $player1, 'seconds' => 1800, 'daysAgo' => 10], // 30 min
            ['id' => self::TIME_02, 'player' => $player2, 'seconds' => 1500, 'daysAgo' => 9],  // 25 min - MIN
            ['id' => self::TIME_03, 'player' => $player3, 'seconds' => 2400, 'daysAgo' => 8],  // 40 min
            ['id' => self::TIME_04, 'player' => $player4, 'seconds' => 3000, 'daysAgo' => 7],  // 50 min - MAX
            ['id' => self::TIME_05, 'player' => $player5, 'seconds' => 2100, 'daysAgo' => 6],  // 35 min
        ]; // Average: 36 min

        foreach ($times as $timeData) {
            $time = $this->createPuzzleSolvingTime(
                id: $timeData['id'],
                player: $timeData['player'],
                puzzle: $puzzle500_01,
                secondsToSolve: $timeData['seconds'],
                daysAgo: $timeData['daysAgo'],
                verified: true,
                firstAttempt: true,
            );
            $manager->persist($time);
            $this->addReference($timeData['id'], $time);
        }

        // PLAYER_REGULAR: Solving the same puzzle (PUZZLE_500_02) 3 times - for testing personal records
        $player1Times = [
            ['id' => self::TIME_06, 'seconds' => 2200, 'daysAgo' => 20], // First attempt
            ['id' => self::TIME_07, 'seconds' => 1900, 'daysAgo' => 15], // Improved
            ['id' => self::TIME_08, 'seconds' => 1700, 'daysAgo' => 10], // Best time
        ];

        foreach ($player1Times as $index => $timeData) {
            $time = $this->createPuzzleSolvingTime(
                id: $timeData['id'],
                player: $player1,
                puzzle: $puzzle500_02,
                secondsToSolve: $timeData['seconds'],
                daysAgo: $timeData['daysAgo'],
                verified: true,
                firstAttempt: $index === 0,
            );
            $manager->persist($time);
            $this->addReference($timeData['id'], $time);
        }

        // Competition solving times - WJPC Qualification Round
        $competitionTimes = [
            ['id' => self::TIME_09, 'player' => $player1, 'seconds' => 1850, 'verified' => true],
            ['id' => self::TIME_10, 'player' => $player2, 'seconds' => 1920, 'verified' => true],
            ['id' => self::TIME_11, 'player' => $player3, 'seconds' => 1780, 'verified' => true],
        ];

        foreach ($competitionTimes as $timeData) {
            $time = $this->createPuzzleSolvingTime(
                id: $timeData['id'],
                player: $timeData['player'],
                puzzle: $puzzle500_01,
                secondsToSolve: $timeData['seconds'],
                daysAgo: 5,
                verified: $timeData['verified'],
                firstAttempt: true,
                competition: $wjpcCompetition,
                competitionRound: $wjpcQualificationRound,
            );
            $manager->persist($time);
            $this->addReference($timeData['id'], $time);
        }

        // Team solving - with PuzzlersGroup
        $teamTime1 = $this->createPuzzleSolvingTime(
            id: self::TIME_12,
            player: $player1,
            puzzle: $puzzle1000_01,
            secondsToSolve: 3600,
            daysAgo: 12,
            verified: true,
            firstAttempt: true,
            team: new PuzzlersGroup(
                teamId: 'team-001',
                puzzlers: [
                    new Puzzler(
                        playerId: $player1->id->toString(),
                        playerName: $player1->name,
                        playerCode: $player1->code,
                        playerCountry: null,
                        isPrivate: false,
                    ),
                    new Puzzler(
                        playerId: $player2->id->toString(),
                        playerName: $player2->name,
                        playerCode: $player2->code,
                        playerCountry: null,
                        isPrivate: false,
                    ),
                ],
            ),
        );
        $manager->persist($teamTime1);
        $this->addReference(self::TIME_12, $teamTime1);

        // More varied solving times for different puzzles and players
        $variedTimes = [
            // PUZZLE_500_03 - multiple players
            ['id' => self::TIME_13, 'player' => $player1, 'puzzle' => $puzzle500_03, 'seconds' => 1950, 'daysAgo' => 25, 'verified' => true],
            ['id' => self::TIME_14, 'player' => $player2, 'puzzle' => $puzzle500_03, 'seconds' => 2100, 'daysAgo' => 24, 'verified' => true],
            ['id' => self::TIME_15, 'player' => $player3, 'puzzle' => $puzzle500_03, 'seconds' => 1800, 'daysAgo' => 23, 'verified' => false],

            // PUZZLE_1000_01 - various players and times
            ['id' => self::TIME_16, 'player' => $player2, 'puzzle' => $puzzle1000_01, 'seconds' => 4200, 'daysAgo' => 22, 'verified' => true],
            ['id' => self::TIME_17, 'player' => $player3, 'puzzle' => $puzzle1000_01, 'seconds' => 3900, 'daysAgo' => 21, 'verified' => true],
            ['id' => self::TIME_18, 'player' => $player4, 'puzzle' => $puzzle1000_01, 'seconds' => 5100, 'daysAgo' => 20, 'verified' => true],

            // PUZZLE_1000_02 - competition times
            ['id' => self::TIME_19, 'player' => $player1, 'puzzle' => $puzzle1000_02, 'seconds' => 4500, 'daysAgo' => 4, 'verified' => true],
            ['id' => self::TIME_20, 'player' => $player2, 'puzzle' => $puzzle1000_02, 'seconds' => 4800, 'daysAgo' => 4, 'verified' => true],

            // PUZZLE_1500_01 - longer puzzles
            ['id' => self::TIME_21, 'player' => $player1, 'puzzle' => $puzzle1500_01, 'seconds' => 7200, 'daysAgo' => 30, 'verified' => true],
            ['id' => self::TIME_22, 'player' => $player3, 'puzzle' => $puzzle1500_01, 'seconds' => 6800, 'daysAgo' => 28, 'verified' => true],
            ['id' => self::TIME_23, 'player' => $player4, 'puzzle' => $puzzle1500_01, 'seconds' => 8100, 'daysAgo' => 26, 'verified' => false],
            ['id' => self::TIME_24, 'player' => $player5, 'puzzle' => $puzzle1500_01, 'seconds' => 7500, 'daysAgo' => 24, 'verified' => true],

            // PUZZLE_2000 - very long puzzle
            ['id' => self::TIME_25, 'player' => $player1, 'puzzle' => $puzzle2000, 'seconds' => 10800, 'daysAgo' => 35, 'verified' => true],
            ['id' => self::TIME_26, 'player' => $player2, 'puzzle' => $puzzle2000, 'seconds' => 12000, 'daysAgo' => 33, 'verified' => true],
            ['id' => self::TIME_27, 'player' => $player5, 'puzzle' => $puzzle2000, 'seconds' => 11500, 'daysAgo' => 31, 'verified' => true],

            // Additional times for PLAYER_REGULAR to have many solved puzzles
            ['id' => self::TIME_28, 'player' => $player1, 'puzzle' => $puzzle500_03, 'seconds' => 1820, 'daysAgo' => 18, 'verified' => true],
            ['id' => self::TIME_29, 'player' => $player1, 'puzzle' => $puzzle1000_02, 'seconds' => 3950, 'daysAgo' => 16, 'verified' => true],

            // Unverified times
            ['id' => self::TIME_30, 'player' => $player2, 'puzzle' => $puzzle500_01, 'seconds' => 1400, 'daysAgo' => 14, 'verified' => false],
            ['id' => self::TIME_31, 'player' => $player3, 'puzzle' => $puzzle500_02, 'seconds' => 2500, 'daysAgo' => 13, 'verified' => false],

            // Fast times
            ['id' => self::TIME_32, 'player' => $player3, 'puzzle' => $puzzle500_01, 'seconds' => 1200, 'daysAgo' => 11, 'verified' => true],
            ['id' => self::TIME_33, 'player' => $player4, 'puzzle' => $puzzle500_02, 'seconds' => 1350, 'daysAgo' => 9, 'verified' => true],

            // Slow times
            ['id' => self::TIME_34, 'player' => $player5, 'puzzle' => $puzzle500_03, 'seconds' => 3200, 'daysAgo' => 8, 'verified' => true],
            ['id' => self::TIME_35, 'player' => $player4, 'puzzle' => $puzzle1000_01, 'seconds' => 6500, 'daysAgo' => 7, 'verified' => true],

            // More recent times
            ['id' => self::TIME_36, 'player' => $player1, 'puzzle' => $puzzle500_01, 'seconds' => 1750, 'daysAgo' => 3, 'verified' => true],
            ['id' => self::TIME_37, 'player' => $player2, 'puzzle' => $puzzle500_02, 'seconds' => 1880, 'daysAgo' => 2, 'verified' => true],
            ['id' => self::TIME_38, 'player' => $player3, 'puzzle' => $puzzle500_03, 'seconds' => 1920, 'daysAgo' => 1, 'verified' => true],

            // Additional variety
            ['id' => self::TIME_39, 'player' => $player5, 'puzzle' => $puzzle1000_01, 'seconds' => 4100, 'daysAgo' => 19, 'verified' => true],
            ['id' => self::TIME_40, 'player' => $player5, 'puzzle' => $puzzle1000_02, 'seconds' => 4650, 'daysAgo' => 17, 'verified' => true],

            // PLAYER_WITH_STRIPE solving borrowed puzzle PUZZLE_1500_02 (borrowed from PLAYER_REGULAR via LENT_05)
            // This enables testing return/pass borrowed puzzle scenarios in solved list
            ['id' => self::TIME_42, 'player' => $player5, 'puzzle' => $puzzle1500_02, 'seconds' => 7000, 'daysAgo' => 5, 'verified' => true],
        ];

        foreach ($variedTimes as $timeData) {
            $competition = null;
            $competitionRound = null;

            // Add some to competition
            if (in_array($timeData['id'], [self::TIME_19, self::TIME_20], true)) {
                $competition = $wjpcCompetition;
                $competitionRound = $wjpcFinalRound;
            }

            $time = $this->createPuzzleSolvingTime(
                id: $timeData['id'],
                player: $timeData['player'],
                puzzle: $timeData['puzzle'],
                secondsToSolve: $timeData['seconds'],
                daysAgo: $timeData['daysAgo'],
                verified: $timeData['verified'],
                firstAttempt: true,
                competition: $competition,
                competitionRound: $competitionRound,
            );
            $manager->persist($time);
            $this->addReference($timeData['id'], $time);
        }

        // Team solving - TIME_41: Team solve for PUZZLE_1000_03
        // PLAYER_PRIVATE is in the team but NOT the player_id owner - this tests team membership detection
        $teamTime2 = $this->createPuzzleSolvingTime(
            id: self::TIME_41,
            player: $player1, // PLAYER_REGULAR is the owner
            puzzle: $puzzle1000_03,
            secondsToSolve: 4000,
            daysAgo: 8,
            verified: true,
            firstAttempt: true,
            team: new PuzzlersGroup(
                teamId: 'team-002',
                puzzlers: [
                    new Puzzler(
                        playerId: $player1->id->toString(),
                        playerName: $player1->name,
                        playerCode: $player1->code,
                        playerCountry: null,
                        isPrivate: false,
                    ),
                    new Puzzler(
                        playerId: $player2->id->toString(),
                        playerName: $player2->name,
                        playerCode: $player2->code,
                        playerCountry: null,
                        isPrivate: false,
                    ),
                ],
            ),
        );
        $manager->persist($teamTime2);
        $this->addReference(self::TIME_41, $teamTime2);

        // PUZZLE_500_05: Solving times for merge testing
        // These records should be migrated to survivor puzzle during merge
        $time43 = $this->createPuzzleSolvingTime(
            id: self::TIME_43,
            player: $player3, // PLAYER_ADMIN
            puzzle: $puzzle500_05,
            secondsToSolve: 2200,
            daysAgo: 14,
            verified: true,
            firstAttempt: true,
        );
        $manager->persist($time43);
        $this->addReference(self::TIME_43, $time43);

        $time44 = $this->createPuzzleSolvingTime(
            id: self::TIME_44,
            player: $player2, // PLAYER_PRIVATE
            puzzle: $puzzle500_05,
            secondsToSolve: 2500,
            daysAgo: 12,
            verified: true,
            firstAttempt: true,
        );
        $manager->persist($time44);
        $this->addReference(self::TIME_44, $time44);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            PuzzleFixture::class,
            CompetitionFixture::class,
            CompetitionRoundFixture::class,
        ];
    }

    private function createPuzzleSolvingTime(
        string $id,
        Player $player,
        Puzzle $puzzle,
        int $secondsToSolve,
        int $daysAgo,
        bool $verified,
        bool $firstAttempt,
        null|Competition $competition = null,
        null|CompetitionRound $competitionRound = null,
        null|PuzzlersGroup $team = null,
        null|string $comment = null,
        null|int $missingPieces = null,
    ): PuzzleSolvingTime {
        $now = $this->clock->now();
        $trackedAt = $now->modify("-{$daysAgo} days");
        $finishedAt = $trackedAt;

        return new PuzzleSolvingTime(
            id: Uuid::fromString($id),
            secondsToSolve: $secondsToSolve,
            player: $player,
            puzzle: $puzzle,
            trackedAt: $trackedAt,
            verified: $verified,
            team: $team,
            finishedAt: $finishedAt,
            comment: $comment,
            finishedPuzzlePhoto: null,
            firstAttempt: $firstAttempt,
            unboxed: false,
            competitionRound: $competitionRound,
            competition: $competition,
            missingPieces: $missingPieces,
        );
    }
}
