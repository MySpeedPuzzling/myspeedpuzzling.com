<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetRelatedPuzzles;
use SpeedPuzzling\Web\Results\RelatedPuzzle;
use SpeedPuzzling\Web\Results\RelatedPuzzles;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * The puzzle page's "More {brand} {N}-piece puzzles" keeps the choice (which puzzles, in which order) per page for
 * days and reads the chosen rows fresh on every view: a renamed or re-pictured puzzle shows at once, one that may not
 * be shown any more (deleted, merged away, unapproved, hidden, moved to another brand or size) makes the choice be
 * computed again - a page never shows fewer or other puzzles than a fresh computation would, only a newly added or
 * revealed puzzle shows up late.
 */
final class GetRelatedPuzzlesCacheTest extends KernelTestCase
{
    private Connection $connection;
    private ArrayAdapter $pool;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->pool = new ArrayAdapter();
    }

    /**
     * Characterization: on a miss and on the hit after it, every approved fixture puzzle's module is the statement's
     */
    public function testCachedEqualsUncachedForEveryFixturePuzzle(): void
    {
        /** @var list<array{id: string, manufacturer_id: string, pieces_count: int}> $puzzles */
        $puzzles = $this->connection->fetchAllAssociative(
            'SELECT id, manufacturer_id, pieces_count FROM puzzle WHERE approved = true AND manufacturer_id IS NOT NULL ORDER BY id',
        );
        self::assertNotEmpty($puzzles);

        $related = $this->related();
        $nonEmpty = 0;

        foreach ($puzzles as $puzzle) {
            $expected = $related->forPuzzle($puzzle['manufacturer_id'], $puzzle['pieces_count'], $puzzle['id']);
            $nonEmpty += $expected->puzzles !== [] ? 1 : 0;

            self::assertEquals($expected, $related->forPuzzleCached($puzzle['manufacturer_id'], $puzzle['pieces_count'], $puzzle['id']), 'miss ' . $puzzle['id']);
            self::assertEquals($expected, $related->forPuzzleCached($puzzle['manufacturer_id'], $puzzle['pieces_count'], $puzzle['id']), 'hit ' . $puzzle['id']);
        }

        self::assertGreaterThan(5, $nonEmpty);
    }

    public function testSecondCallReusesTheChoice(): void
    {
        $related = $this->related();
        $first = $related->forPuzzleCached(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, PuzzleFixture::PUZZLE_500_01);

        $newFavourite = $this->addPuzzle(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, solves: 1000);

        self::assertSame(self::ids($first), self::ids($related->forPuzzleCached(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, PuzzleFixture::PUZZLE_500_01)));
        self::assertCount(1, $this->pool->getValues());
        // Computed afresh it would be first
        self::assertSame($newFavourite, self::ids($related->forPuzzle(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, PuzzleFixture::PUZZLE_500_01))[0]);
    }

    public function testRowsAreReadFreshOnAHit(): void
    {
        $related = $this->related();
        $first = $related->forPuzzleCached(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, PuzzleFixture::PUZZLE_500_01);
        self::assertGreaterThanOrEqual(2, count($first->puzzles));
        $renamed = $first->puzzles[0]->puzzleId;
        $pictureHidden = $first->puzzles[1]->puzzleId;

        $this->connection->executeStatement(
            "UPDATE puzzle SET name = 'Fresh Name', image = 'puzzles/fresh.jpg', image_ratio = 1.5 WHERE id = :id",
            ['id' => $renamed],
        );
        $this->connection->executeStatement(
            'UPDATE puzzle SET hide_image_until = :until WHERE id = :id',
            ['until' => TestClock::now()->modify('+30 days')->format('Y-m-d H:i:s'), 'id' => $pictureHidden],
        );

        $hit = $related->forPuzzleCached(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, PuzzleFixture::PUZZLE_500_01);

        self::assertSame(self::ids($first), self::ids($hit));
        self::assertSame('Fresh Name', $hit->puzzles[0]->puzzleName);
        self::assertSame('puzzles/fresh.jpg', $hit->puzzles[0]->puzzleImage);
        self::assertSame(1.5, $hit->puzzles[0]->puzzleImageRatio);
        self::assertNull($hit->puzzles[1]->puzzleImage);
        self::assertNull($hit->puzzles[1]->puzzleImageRatio);
        // Everything a fresh computation would show for these puzzles
        self::assertEquals($related->forPuzzle(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, PuzzleFixture::PUZZLE_500_01), $hit);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDisappearances(): iterable
    {
        yield 'hidden' => ["UPDATE puzzle SET hide_until = TIMESTAMP '2999-01-01 00:00:00' WHERE id = :id"];
        yield 'unapproved' => ['UPDATE puzzle SET approved = false WHERE id = :id'];
        yield 'deleted' => ['DELETE FROM puzzle_statistics WHERE puzzle_id = :id; DELETE FROM puzzle WHERE id = :id'];
        yield 'moved to another brand' => ["UPDATE puzzle SET manufacturer_id = '" . ManufacturerFixture::MANUFACTURER_TREFL . "' WHERE id = :id"];
        yield 'another piece count' => ['UPDATE puzzle SET pieces_count = 999 WHERE id = :id'];
    }

    #[DataProvider('provideDisappearances')]
    public function testAChosenPuzzleThatMayNotBeShownAnyMoreIsRecomputed(string $change): void
    {
        $related = $this->related();
        // A puzzle of the module, chosen for this page only - nothing else refers to it
        $gone = $this->addPuzzle(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, solves: 1000);
        $first = $related->forPuzzleCached(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, PuzzleFixture::PUZZLE_500_01);
        self::assertContains($gone, self::ids($first));

        foreach (explode('; ', $change) as $statement) {
            $this->connection->executeStatement($statement, ['id' => $gone]);
        }

        $after = $related->forPuzzleCached(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, PuzzleFixture::PUZZLE_500_01);

        self::assertNotContains($gone, self::ids($after));
        self::assertEquals($related->forPuzzle(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, PuzzleFixture::PUZZLE_500_01), $after);
        // ... and stored again: the next view is a hit with the new choice
        self::assertEquals($after, $related->forPuzzleCached(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, PuzzleFixture::PUZZLE_500_01));
    }

    public function testAHiddenPuzzleNeverShowsBeforeItsMoment(): void
    {
        // Chosen while visible, hidden again later by hand: never shown while hidden
        $puzzleId = $this->addPuzzle(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, solves: 1000);
        $first = $this->related()->forPuzzleCached(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, PuzzleFixture::PUZZLE_500_01);
        self::assertContains($puzzleId, self::ids($first));

        $this->connection->executeStatement(
            'UPDATE puzzle SET hide_until = :until WHERE id = :id',
            ['until' => TestClock::now()->modify('+1 day')->format('Y-m-d H:i:s'), 'id' => $puzzleId],
        );

        $aMomentBefore = new GetRelatedPuzzles($this->connection, new MockClock(TestClock::now()->modify('+1 day -1 second')), $this->pool);
        self::assertNotContains($puzzleId, self::ids($aMomentBefore->forPuzzleCached(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, PuzzleFixture::PUZZLE_500_01)));
    }

    public function testEachPageHasItsOwnEntry(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->addPuzzle(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, solves: 1);
        }

        $related = $this->related();

        foreach ([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02] as $page) {
            $related->forPuzzleCached(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, $page);
        }

        self::assertCount(2, $this->pool->getValues());

        foreach ([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02] as $page) {
            self::assertEquals(
                $related->forPuzzle(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, $page),
                $related->forPuzzleCached(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, $page),
            );
        }
    }

    public function testTheKeyFollowsBrandAndPieces(): void
    {
        $related = $this->related();
        $related->forPuzzleCached(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, PuzzleFixture::PUZZLE_500_01);

        // The same page after its puzzle moved to another brand and size: that combination's module
        self::assertEquals(
            $related->forPuzzle(ManufacturerFixture::MANUFACTURER_TREFL, 1000, PuzzleFixture::PUZZLE_500_01),
            $related->forPuzzleCached(ManufacturerFixture::MANUFACTURER_TREFL, 1000, PuzzleFixture::PUZZLE_500_01),
        );
        self::assertCount(2, $this->pool->getValues());
    }

    public function testTheContainersServiceIsCached(): void
    {
        $related = self::getContainer()->get(GetRelatedPuzzles::class);
        $first = $related->forPuzzleCached(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, PuzzleFixture::PUZZLE_500_01);

        $this->addPuzzle(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, solves: 1000);

        self::assertSame(self::ids($first), self::ids($related->forPuzzleCached(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, PuzzleFixture::PUZZLE_500_01)));
    }

    private function related(): GetRelatedPuzzles
    {
        return new GetRelatedPuzzles($this->connection, new MockClock(TestClock::now()), $this->pool);
    }

    private function addPuzzle(string $manufacturerId, int $piecesCount, int $solves): string
    {
        $puzzleId = Uuid::uuid7()->toString();

        $this->connection->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, manufacturer_id, is_available)
             VALUES (:id, :piecesCount, :name, true, :manufacturerId, true)",
            [
                'id' => $puzzleId,
                'piecesCount' => $piecesCount,
                'name' => 'Zz cached related ' . $puzzleId,
                'manufacturerId' => $manufacturerId,
            ],
        );
        $this->connection->executeStatement(
            'INSERT INTO puzzle_statistics (puzzle_id, solved_times_count) VALUES (:id, :solves)',
            ['id' => $puzzleId, 'solves' => $solves],
        );

        return $puzzleId;
    }

    /**
     * @return list<string>
     */
    private static function ids(RelatedPuzzles $related): array
    {
        return array_map(static fn (RelatedPuzzle $puzzle): string => $puzzle->puzzleId, $related->puzzles);
    }
}
