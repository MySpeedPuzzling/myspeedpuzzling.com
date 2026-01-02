<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleStatistics;
use SpeedPuzzling\Web\Repository\PuzzleStatisticsRepository;
use SpeedPuzzling\Web\Services\PuzzleStatisticsCalculator;

final class PuzzleStatisticsFixture extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private readonly PuzzleStatisticsCalculator $calculator,
        private readonly PuzzleStatisticsRepository $repository,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Calculate statistics for all puzzles that have solving times
        $puzzleIds = [
            PuzzleFixture::PUZZLE_500_01,
            PuzzleFixture::PUZZLE_500_02,
            PuzzleFixture::PUZZLE_500_03,
            PuzzleFixture::PUZZLE_1000_01,
            PuzzleFixture::PUZZLE_1000_02,
            PuzzleFixture::PUZZLE_1000_03,
            PuzzleFixture::PUZZLE_1500_01,
            PuzzleFixture::PUZZLE_1500_02,
            PuzzleFixture::PUZZLE_2000,
        ];

        foreach ($puzzleIds as $puzzleId) {
            $puzzle = $this->getReference($puzzleId, Puzzle::class);
            $data = $this->calculator->calculateForPuzzle(Uuid::fromString($puzzleId));

            $stats = $this->repository->findByPuzzleId(Uuid::fromString($puzzleId));

            if ($stats === null) {
                $stats = new PuzzleStatistics($puzzle);
                $manager->persist($stats);
            }

            $stats->update($data);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PuzzleFixture::class,
            PuzzleSolvingTimeFixture::class,
        ];
    }
}
