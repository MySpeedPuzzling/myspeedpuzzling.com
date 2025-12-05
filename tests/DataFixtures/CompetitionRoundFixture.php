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
use SpeedPuzzling\Web\Entity\Puzzle;

final class CompetitionRoundFixture extends Fixture implements DependentFixtureInterface
{
    public const string ROUND_WJPC_QUALIFICATION = '018d0005-0000-0000-0000-000000000001';
    public const string ROUND_WJPC_FINAL = '018d0005-0000-0000-0000-000000000002';
    public const string ROUND_CZECH_FINAL = '018d0005-0000-0000-0000-000000000003';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $wjpcCompetition = $this->getReference(CompetitionFixture::COMPETITION_WJPC_2024, Competition::class);
        $czechCompetition = $this->getReference(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, Competition::class);

        $puzzle500_01 = $this->getReference(PuzzleFixture::PUZZLE_500_01, Puzzle::class);
        $puzzle500_02 = $this->getReference(PuzzleFixture::PUZZLE_500_02, Puzzle::class);
        $puzzle1000_01 = $this->getReference(PuzzleFixture::PUZZLE_1000_01, Puzzle::class);
        $puzzle1000_02 = $this->getReference(PuzzleFixture::PUZZLE_1000_02, Puzzle::class);

        $wjpcQualificationRound = $this->createCompetitionRound(
            id: self::ROUND_WJPC_QUALIFICATION,
            competition: $wjpcCompetition,
            name: 'Qualification Round',
            minutesLimit: 60,
            daysFromNow: 30,
            puzzles: [$puzzle500_01, $puzzle500_02],
            badgeBackgroundColor: '#007bff',
            badgeTextColor: '#ffffff',
        );
        $manager->persist($wjpcQualificationRound);
        $this->addReference(self::ROUND_WJPC_QUALIFICATION, $wjpcQualificationRound);

        $wjpcFinalRound = $this->createCompetitionRound(
            id: self::ROUND_WJPC_FINAL,
            competition: $wjpcCompetition,
            name: 'Final Round',
            minutesLimit: 120,
            daysFromNow: 32,
            puzzles: [$puzzle1000_01, $puzzle1000_02],
            badgeBackgroundColor: '#ffc107',
            badgeTextColor: '#000000',
        );
        $manager->persist($wjpcFinalRound);
        $this->addReference(self::ROUND_WJPC_FINAL, $wjpcFinalRound);

        $czechFinalRound = $this->createCompetitionRound(
            id: self::ROUND_CZECH_FINAL,
            competition: $czechCompetition,
            name: 'Final Round',
            minutesLimit: 90,
            daysFromNow: 60,
            puzzles: [$puzzle500_01],
        );
        $manager->persist($czechFinalRound);
        $this->addReference(self::ROUND_CZECH_FINAL, $czechFinalRound);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            CompetitionFixture::class,
            PuzzleFixture::class,
        ];
    }

    /**
     * @param array<Puzzle> $puzzles
     */
    private function createCompetitionRound(
        string $id,
        Competition $competition,
        string $name,
        int $minutesLimit,
        int $daysFromNow,
        array $puzzles = [],
        null|string $badgeBackgroundColor = null,
        null|string $badgeTextColor = null,
    ): CompetitionRound {
        $startsAt = $this->clock->now()->modify("+{$daysFromNow} days");

        $round = new CompetitionRound(
            id: Uuid::fromString($id),
            competition: $competition,
            name: $name,
            minutesLimit: $minutesLimit,
            startsAt: $startsAt,
            badgeBackgroundColor: $badgeBackgroundColor,
            badgeTextColor: $badgeTextColor,
        );

        foreach ($puzzles as $puzzle) {
            $round->puzzles->add($puzzle);
        }

        return $round;
    }
}
