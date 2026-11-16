<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Ids are time-ordered (version 7) wherever they are created: Uuid::uuid7() in PHP, gen_uuid_v7() in a set-based
 * statement that creates rows by itself (.claude/rules/database.md "Ids created in SQL"). The insights
 * recalculation's INSERTs used to call gen_random_uuid() - version 4, a random page of the primary key index for
 * every row - because Uuid::uuid7() was the rule for PHP and nothing said what SQL should call. Every file in src/
 * that asks the database for a random id fails this test.
 */
final class SqlCreatedIdsCoverageTest extends TestCase
{
    private const string PATTERN = '~gen_random_uuid|uuid_generate_v\d|uuidv4\s*\(~i';

    public function testApplicationSqlCreatesNoRandomIds(): void
    {
        $offending = [];
        $root = (string) realpath(__DIR__ . '/../src');

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match(self::PATTERN, (string) file_get_contents($file->getPathname())) === 1) {
                $offending[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        sort($offending);

        self::assertSame([], $offending, 'These files create random (version 4) ids in SQL. Use gen_uuid_v7() - or Uuid::uuid7() where the id can come from PHP. See .claude/rules/database.md.');
    }

    public function testThePatternCatchesTheRandomGenerators(): void
    {
        self::assertSame(1, preg_match(self::PATTERN, 'SELECT gen_random_uuid(), player_id FROM player'));
        self::assertSame(1, preg_match(self::PATTERN, 'VALUES (uuid_generate_v4(), :name)'));
        self::assertSame(1, preg_match(self::PATTERN, 'SELECT uuidv4()'));

        self::assertSame(0, preg_match(self::PATTERN, 'SELECT gen_uuid_v7(), player_id FROM player'));
        self::assertSame(0, preg_match(self::PATTERN, '$id = Uuid::uuid7();'));
    }
}
