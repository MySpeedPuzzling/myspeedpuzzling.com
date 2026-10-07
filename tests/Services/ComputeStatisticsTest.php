<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\ComputeStatistics;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\Statistics\PerCategoryStatistics;
use SpeedPuzzling\Web\Value\Statistics\PiecesStatistics;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ComputeStatisticsTest extends KernelTestCase
{
    public function testSuspiciousResultIsNeitherCountedNorTimed(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $computeStatistics = $container->get(ComputeStatistics::class);
        $database = $container->get(Connection::class);

        /** @var array{id: string, seconds_to_solve: int, pieces_count: int} $fastest */
        $fastest = $database->fetchAssociative(
            "SELECT pst.id, pst.seconds_to_solve, puzzle.pieces_count
            FROM puzzle_solving_time pst
            INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
            WHERE pst.player_id = :playerId AND pst.puzzling_type = 'solo' AND pst.seconds_to_solve IS NOT NULL
            ORDER BY pst.seconds_to_solve, pst.id
            LIMIT 1",
            ['playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        $before = $this->solo($computeStatistics);
        $piecesBefore = $this->piecesOf($before, $fastest['pieces_count']);
        self::assertNotNull($piecesBefore);
        self::assertSame($fastest['seconds_to_solve'], $piecesBefore->fastestTime);

        $database->executeStatement('UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id', ['id' => $fastest['id']]);

        $after = $this->solo($computeStatistics);
        self::assertSame($before->solvedPuzzle->count - 1, $after->solvedPuzzle->count);

        $piecesAfter = $this->piecesOf($after, $fastest['pieces_count']);

        if ($piecesBefore->count === 1) {
            self::assertNull($piecesAfter);

            return;
        }

        self::assertNotNull($piecesAfter);
        self::assertSame($piecesBefore->count - 1, $piecesAfter->count);
        self::assertGreaterThanOrEqual($fastest['seconds_to_solve'], $piecesAfter->fastestTime);
    }

    private function solo(ComputeStatistics $computeStatistics): PerCategoryStatistics
    {
        [, $solo] = $computeStatistics->forPlayer(
            PlayerFixture::PLAYER_REGULAR,
            new DateTimeImmutable('2000-01-01'),
            new DateTimeImmutable('+1 day'),
            false,
        );

        return $solo;
    }

    private function piecesOf(PerCategoryStatistics $statistics, int $pieces): null|PiecesStatistics
    {
        foreach ($statistics->perPieces as $piecesStatistics) {
            if ($piecesStatistics->pieces === $pieces) {
                return $piecesStatistics;
            }
        }

        return null;
    }
}
