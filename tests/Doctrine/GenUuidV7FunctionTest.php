<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Rfc4122\FieldsInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * gen_uuid_v7() (Version20261116160000) is for the ids of the rows SQL inserts by itself - set-based
 * INSERT ... SELECT statements like the insights recalculation's, which cannot take them from PHP. It must give what
 * Uuid::uuid7() gives: a version 7 UUID that starts with the time, so ids created together sit together in the primary
 * key index (a random version 4 id lands on a random page of it - .claude/rules/database.md "Ids created in SQL").
 */
final class GenUuidV7FunctionTest extends KernelTestCase
{
    private const string MIGRATION = __DIR__ . '/../../migrations/Version20261116160000.php';
    private const string TEST_DATABASE = __DIR__ . '/../bootstrap.php';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;
    }

    public function testItIsAVersion7UuidThePhpLibraryReadsLikeItsOwn(): void
    {
        $id = Uuid::fromString($this->newId());
        $fields = $id->getFields();

        self::assertInstanceOf(FieldsInterface::class, $fields);
        self::assertSame(7, $fields->getVersion());
        self::assertSame(Uuid::RFC_4122, $fields->getVariant());
    }

    public function testEveryRowOfAStatementGetsItsOwnVersion7Id(): void
    {
        /** @var array{total: int|string, distinct_ids: int|string, version_7: int|string, rfc_variant: int|string} $counts */
        $counts = $this->connection->fetchAssociative(<<<'SQL'
SELECT
    COUNT(*) AS total,
    COUNT(DISTINCT id) AS distinct_ids,
    COUNT(*) FILTER (WHERE substring(CAST(id AS text) FROM 15 FOR 1) = '7') AS version_7,
    COUNT(*) FILTER (WHERE get_byte(uuid_send(id), 8) >> 6 = 2) AS rfc_variant
FROM (SELECT gen_uuid_v7() AS id FROM generate_series(1, 100000)) AS ids
SQL);

        self::assertSame(
            ['total' => 100000, 'distinct_ids' => 100000, 'version_7' => 100000, 'rfc_variant' => 100000],
            array_map(intval(...), $counts),
        );
    }

    /**
     * The first 48 bits are Unix milliseconds, as in Uuid::uuid7(): an id created between two PHP ids carries a time
     * between theirs. A minute of slack for a database on another machine - bytes in the wrong place are decades off.
     */
    public function testItStartsWithTheTimeItWasCreatedAt(): void
    {
        $before = self::milliseconds(Uuid::uuid7()->toString());
        $created = self::milliseconds($this->newId());
        $after = self::milliseconds(Uuid::uuid7()->toString());

        self::assertGreaterThanOrEqual($before - 60_000, $created);
        self::assertLessThanOrEqual($after + 60_000, $created);
    }

    /**
     * What keeps the rows of one run on the same few pages of the primary key index. Within one millisecond the
     * order is random, as it is for ids two PHP processes create - a few rows apart, never a page apart.
     */
    public function testIdsCreatedMillisecondsApartSortInTheOrderTheyWereCreated(): void
    {
        $ids = [];

        for ($i = 0; $i < 20; $i++) {
            $ids[] = $this->newId();
            $this->connection->executeStatement('SELECT pg_sleep(0.003)');
        }

        /** @var list<string> $sortedByTheDatabase */
        $sortedByTheDatabase = $this->connection->fetchFirstColumn(
            'SELECT CAST(id AS text) FROM unnest(CAST(:ids AS uuid[])) WITH ORDINALITY AS created (id, position) ORDER BY id',
            ['ids' => '{' . implode(',', $ids) . '}'],
        );

        self::assertSame($ids, $sortedByTheDatabase);
    }

    /**
     * VOLATILE: a new id per row, never one per statement. PARALLEL SAFE, as gen_random_uuid() is: an unsafe
     * function forbids a parallel plan to the whole statement that calls it.
     */
    public function testItIsVolatileAndParallelSafe(): void
    {
        self::assertSame(
            ['provolatile' => 'v', 'proparallel' => 's'],
            $this->connection->fetchAssociative("SELECT provolatile, proparallel FROM pg_proc WHERE proname = 'gen_uuid_v7'"),
        );
    }

    /**
     * The test database is built without migrations (tests/bootstrap.php), so the function exists twice in the
     * repository - and the suite must test the one production gets.
     */
    public function testTheTestDatabaseHasTheFunctionOfTheMigration(): void
    {
        $migration = self::functionDefinition(self::MIGRATION);

        self::assertStringContainsString('RETURNS uuid', $migration);
        self::assertSame($migration, self::functionDefinition(self::TEST_DATABASE));
    }

    private function newId(): string
    {
        $id = $this->connection->fetchOne('SELECT gen_uuid_v7()');
        self::assertIsString($id);

        return $id;
    }

    /**
     * The 48-bit time prefix of a version 7 UUID
     */
    private static function milliseconds(string $uuid): int
    {
        return (int) hexdec(substr(str_replace('-', '', $uuid), 0, 12));
    }

    /**
     * The CREATE FUNCTION statement of gen_uuid_v7() as the file has it, whitespace aside
     */
    private static function functionDefinition(string $path): string
    {
        $found = preg_match(
            '~CREATE OR REPLACE FUNCTION gen_uuid_v7\(\).*?\$\$.*?\$\$~s',
            (string) file_get_contents($path),
            $matches,
        );

        self::assertSame(1, $found, sprintf('No gen_uuid_v7() definition in %s', basename($path)));

        return trim((string) preg_replace('~\s+~', ' ', $matches[0]));
    }
}
