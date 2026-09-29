<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetPuzzleIdsForSitemap;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPuzzleIdsForSitemapTest extends KernelTestCase
{
    private GetPuzzleIdsForSitemap $query;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPuzzleIdsForSitemap::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testLastmodIsTheLatestSolve(): void
    {
        // PUZZLE_500_01 has plenty of solves
        $this->setDates(PuzzleFixture::PUZZLE_500_01, addedAt: '2024-01-01 10:00:00', approvedAt: '2024-06-01 10:00:00', solvesTrackedAt: '2025-03-04 10:00:00');
        $this->database->executeStatement(
            "UPDATE puzzle_solving_time SET tracked_at = '2025-05-06 23:10:00'
             WHERE id = (SELECT id FROM puzzle_solving_time WHERE puzzle_id = :puzzleId ORDER BY id LIMIT 1)",
            ['puzzleId' => PuzzleFixture::PUZZLE_500_01],
        );

        self::assertSame('2025-05-06', $this->lastmodOf(PuzzleFixture::PUZZLE_500_01));
    }

    public function testLastmodIsTheApprovalWhenItCameLast(): void
    {
        $this->setDates(PuzzleFixture::PUZZLE_500_01, addedAt: '2024-01-01 10:00:00', approvedAt: '2025-08-09 10:00:00', solvesTrackedAt: '2024-02-02 10:00:00');

        self::assertSame('2025-08-09', $this->lastmodOf(PuzzleFixture::PUZZLE_500_01));
    }

    public function testLastmodOfAPuzzleWithoutSolvesIsWhenItWasAdded(): void
    {
        $this->setDates(PuzzleFixture::PUZZLE_500_04, addedAt: '2024-01-01 10:00:00', approvedAt: null, solvesTrackedAt: null);

        self::assertSame('2024-01-01', $this->lastmodOf(PuzzleFixture::PUZZLE_500_04));
    }

    public function testNoLastmodWithoutAnyDate(): void
    {
        $this->setDates(PuzzleFixture::PUZZLE_500_04, addedAt: null, approvedAt: null, solvesTrackedAt: null);

        self::assertNull($this->lastmodOf(PuzzleFixture::PUZZLE_500_04));
    }

    public function testImagePagesUseTheSameLastmod(): void
    {
        $this->setDates(PuzzleFixture::PUZZLE_500_01, addedAt: '2024-01-01 10:00:00', approvedAt: null, solvesTrackedAt: '2025-03-04 10:00:00');
        $this->database->executeStatement(
            "UPDATE puzzle SET image = 'box.jpg' WHERE id = :id",
            ['id' => PuzzleFixture::PUZZLE_500_01],
        );

        $rows = array_values(array_filter(
            $this->query->approvedPageWithImages(limit: 10_000, offset: 0),
            static fn (array $row): bool => $row['id'] === PuzzleFixture::PUZZLE_500_01,
        ));

        self::assertCount(1, $rows);
        self::assertSame('2025-03-04', $rows[0]['lastmod']);
        self::assertSame('box.jpg', $rows[0]['image']);
    }

    public function testPagingKeepsTheIdOrderAndEveryPuzzleOnce(): void
    {
        $all = array_column($this->query->approvedPage(limit: 10_000, offset: 0), 'id');
        $firstPage = array_column($this->query->approvedPage(limit: 5, offset: 0), 'id');
        $secondPage = array_column($this->query->approvedPage(limit: 5, offset: 5), 'id');

        self::assertCount($this->query->countApproved(), $all);
        self::assertSame(array_slice($all, 0, 10), [...$firstPage, ...$secondPage]);

        $sorted = $all;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $all);
    }

    /**
     * @param null|string $solvesTrackedAt null = the puzzle has no solves at all
     */
    private function setDates(string $puzzleId, null|string $addedAt, null|string $approvedAt, null|string $solvesTrackedAt): void
    {
        $this->database->executeStatement(
            'UPDATE puzzle SET added_at = :addedAt, approved_at = :approvedAt WHERE id = :id',
            ['id' => $puzzleId, 'addedAt' => $addedAt, 'approvedAt' => $approvedAt],
        );

        if ($solvesTrackedAt === null) {
            $this->database->executeStatement(
                'DELETE FROM puzzle_solving_time WHERE puzzle_id = :puzzleId',
                ['puzzleId' => $puzzleId],
            );

            return;
        }

        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET tracked_at = :trackedAt WHERE puzzle_id = :puzzleId',
            ['puzzleId' => $puzzleId, 'trackedAt' => $solvesTrackedAt],
        );
    }

    private function lastmodOf(string $puzzleId): null|string
    {
        foreach ($this->query->approvedPage(limit: 10_000, offset: 0) as $row) {
            if ($row['id'] === $puzzleId) {
                return $row['lastmod'];
            }
        }

        self::fail(sprintf('Puzzle %s is not in the sitemap', $puzzleId));
    }
}
