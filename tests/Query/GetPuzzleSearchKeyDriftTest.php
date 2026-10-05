<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetPuzzleSearchKeyDrift;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The dry run of myspeedpuzzling:rebuild-puzzle-search-keys: what a run would write, without writing it.
 */
final class GetPuzzleSearchKeyDriftTest extends KernelTestCase
{
    private Connection $database;
    private GetPuzzleSearchKeyDrift $getPuzzleSearchKeyDrift;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->getPuzzleSearchKeyDrift = self::getContainer()->get(GetPuzzleSearchKeyDrift::class);
    }

    public function testKeysWrittenByTheEntityAreNoDrift(): void
    {
        // Every fixture puzzle got its keys from the entity - the dry run must build the very same ones
        /** @var list<string> $allIds */
        $allIds = $this->database->fetchFirstColumn('SELECT id FROM puzzle ORDER BY id');

        self::assertSame([], $this->getPuzzleSearchKeyDrift->forIds($allIds));
    }

    public function testAStaleKeyIsReportedAndNothingIsWritten(): void
    {
        $stored = $this->keysOf(PuzzleFixture::PUZZLE_1000_05);

        // A key written around the entity - by SQL, or by the previous release during a deploy
        $this->database->executeStatement(
            "UPDATE puzzle SET search_codes = E'\\nc:old\\n' WHERE id = :id",
            ['id' => PuzzleFixture::PUZZLE_1000_05],
        );

        $drift = $this->getPuzzleSearchKeyDrift->forIds([PuzzleFixture::PUZZLE_1000_05, PuzzleFixture::PUZZLE_500_01]);

        self::assertCount(1, $drift);
        self::assertSame(PuzzleFixture::PUZZLE_1000_05, $drift[0]->puzzleId);
        self::assertSame("\nc:old\n", $drift[0]->storedCodes);
        self::assertSame($stored['search_codes'], $drift[0]->expectedCodes);
        self::assertSame($stored['search_names'], $drift[0]->expectedNames);
        self::assertSame("\nc:old\n", $this->keysOf(PuzzleFixture::PUZZLE_1000_05)['search_codes'], 'the dry run writes nothing');
    }

    public function testNoIdsNoQuery(): void
    {
        self::assertSame([], $this->getPuzzleSearchKeyDrift->forIds([]));
    }

    /**
     * @return array{search_names: null|string, search_codes: null|string}
     */
    private function keysOf(string $puzzleId): array
    {
        /** @var array{search_names: null|string, search_codes: null|string} $row */
        $row = $this->database->fetchAssociative('SELECT search_names, search_codes FROM puzzle WHERE id = :id', ['id' => $puzzleId]);

        return $row;
    }
}
