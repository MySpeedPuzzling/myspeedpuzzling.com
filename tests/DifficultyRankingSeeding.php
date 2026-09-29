<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleDifficulty;
use SpeedPuzzling\Web\Entity\PuzzleStatistics;
use SpeedPuzzling\Web\Value\MetricConfidence;
use SpeedPuzzling\Web\Value\PuzzleStatisticsData;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\CacheClearer\Psr6CacheClearer;

/**
 * Seeds rated puzzles for the public hardest / easiest lists. The fixtures
 * have no puzzle with a medium/high confidence difficulty (the recalculation
 * is a batch job), so every test seeds exactly the list it asserts on; DAMA
 * rolls the rows back with the test.
 *
 * The lists are cached in the difficulty_rankings_cache pool, an in-memory
 * pool in the test environment (config/packages/test/cache.php): every kernel -
 * every BrowserKit request - starts empty, so nothing built from rolled-back
 * rows outlives its test. Within one kernel the cache holds, which is what
 * clearDifficultyRankingsCache() is for.
 */
trait DifficultyRankingSeeding
{
    /**
     * Approved puzzles, each with a difficulty of the given confidence. The
     * scores fall by $step from $highestScore, so the first id is the hardest.
     *
     * @return list<string> puzzle ids, hardest first
     */
    protected function seedRatedPuzzles(
        ContainerInterface $container,
        string $manufacturerId,
        int $piecesCount,
        int $count,
        string $namePrefix,
        float $highestScore = 1.9137,
        float $step = 0.0113,
        MetricConfidence $confidence = MetricConfidence::Medium,
        int $sampleSize = 12,
    ): array {
        $entityManager = $this->rankingEntityManager($container);

        $manufacturer = $entityManager->find(Manufacturer::class, $manufacturerId);
        self::assertNotNull($manufacturer);

        $puzzleIds = [];

        for ($i = 0; $i < $count; $i++) {
            $puzzleIds[] = $this->persistRatedPuzzle(
                $entityManager,
                $manufacturer,
                $piecesCount,
                sprintf('%s %03d', $namePrefix, $i + 1),
                round($highestScore - $i * $step, 4),
                $confidence,
                $sampleSize,
            );
        }

        $entityManager->flush();
        $entityManager->clear();

        return $puzzleIds;
    }

    /**
     * One puzzle with full control over everything the list query filters or sorts on.
     */
    protected function seedRatedPuzzle(
        ContainerInterface $container,
        string $manufacturerId,
        int $piecesCount,
        string $name,
        null|float $score,
        MetricConfidence $confidence = MetricConfidence::Medium,
        int $sampleSize = 12,
        bool $approved = true,
        null|DateTimeImmutable $hideUntil = null,
        null|DateTimeImmutable $hideImageUntil = null,
        null|PuzzleStatisticsData $statistics = null,
    ): string {
        $entityManager = $this->rankingEntityManager($container);

        $manufacturer = $entityManager->find(Manufacturer::class, $manufacturerId);
        self::assertNotNull($manufacturer);

        $puzzleId = $this->persistRatedPuzzle(
            $entityManager,
            $manufacturer,
            $piecesCount,
            $name,
            $score,
            $confidence,
            $sampleSize,
            $approved,
            $hideUntil,
            $hideImageUntil,
            $statistics,
        );

        $entityManager->flush();
        $entityManager->clear();

        return $puzzleId;
    }

    protected function clearDifficultyRankingsCache(ContainerInterface $container): void
    {
        // The public clearer behind cache:pool:clear - the pool itself is a private service
        /** @var Psr6CacheClearer $clearer */
        $clearer = $container->get('cache.global_clearer');
        self::assertTrue($clearer->hasPool('difficulty_rankings_cache'));

        $clearer->clearPool('difficulty_rankings_cache');
    }

    private function persistRatedPuzzle(
        EntityManagerInterface $entityManager,
        Manufacturer $manufacturer,
        int $piecesCount,
        string $name,
        null|float $score,
        MetricConfidence $confidence,
        int $sampleSize,
        bool $approved = true,
        null|DateTimeImmutable $hideUntil = null,
        null|DateTimeImmutable $hideImageUntil = null,
        null|PuzzleStatisticsData $statistics = null,
    ): string {
        $puzzle = new Puzzle(
            id: Uuid::uuid7(),
            piecesCount: $piecesCount,
            name: $name,
            approved: $approved,
            image: 'puzzles/' . strtolower(str_replace(' ', '-', $name)) . '.jpg',
            manufacturer: $manufacturer,
            hideImageUntil: $hideImageUntil,
            hideUntil: $hideUntil,
        );
        $entityManager->persist($puzzle);

        $difficulty = new PuzzleDifficulty($puzzle);
        $difficulty->updateDifficulty($score, $confidence, $sampleSize, new DateTimeImmutable());
        $entityManager->persist($difficulty);

        if ($statistics !== null) {
            $puzzleStatistics = new PuzzleStatistics($puzzle);
            $puzzleStatistics->update($statistics);
            $entityManager->persist($puzzleStatistics);
        }

        return $puzzle->id->toString();
    }

    private function rankingEntityManager(ContainerInterface $container): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
