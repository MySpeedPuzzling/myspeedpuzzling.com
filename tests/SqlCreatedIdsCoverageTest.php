<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Ids are time-ordered (version 7) wherever they are created: Uuid::uuid7() in PHP, gen_uuid_v7() in a set-based
 * statement that creates rows by itself, assets/uuid_v7.js in the browser (.claude/rules/database.md "Ids created in
 * SQL"). The insights recalculation's INSERTs used to call gen_random_uuid() - version 4, a random page of the primary
 * key index for every row - because Uuid::uuid7() was the rule for PHP and nothing said what SQL should call; the
 * official results tools sent crypto.randomUUID() ids that became primary keys of receipts, participants, participant
 * rounds and teams. Every file in src/ that asks the database or PHP for a random id, and every script in assets/ that
 * asks the browser for one, fails this test.
 *
 * Version 5 (Uuid::uuid5) is not forbidden: it is a name-based id, the same name gives the same id - the advisory lock
 * keys and the API's Idempotency-Key retry detection (CreateSolvingTimeProcessor) depend on exactly that.
 */
final class SqlCreatedIdsCoverageTest extends TestCase
{
    private const string PATTERN = '~gen_random_uuid|uuid_generate_v\d|uuidv4\s*\(~i';

    // ramsey/uuid Uuid::uuid4(), symfony/uid Uuid::v4() / new UuidV4()
    private const string PHP_PATTERN = '~\buuid4\s*\(|\bUuid::v4\s*\(|\bUuidV4\b~i';

    // crypto.randomUUID() is always version 4
    private const string BROWSER_PATTERN = '~\brandomUUID\b~';

    public function testApplicationSqlCreatesNoRandomIds(): void
    {
        $offending = $this->filesMatching(__DIR__ . '/../src', ['php'], self::PATTERN);

        self::assertSame([], $offending, 'These files create random (version 4) ids in SQL. Use gen_uuid_v7() - or Uuid::uuid7() where the id can come from PHP. See .claude/rules/database.md.');
    }

    public function testApplicationPhpCreatesNoRandomIds(): void
    {
        $offending = $this->filesMatching(__DIR__ . '/../src', ['php'], self::PHP_PATTERN);

        self::assertSame([], $offending, 'These files create random (version 4) ids in PHP. Use Uuid::uuid7(). See .claude/rules/database.md.');
    }

    public function testBrowserScriptsCreateNoRandomIds(): void
    {
        $offending = $this->filesMatching(__DIR__ . '/../assets', ['js', 'mjs'], self::BROWSER_PATTERN);

        self::assertSame([], $offending, 'These scripts create random (version 4) ids in the browser (crypto.randomUUID). Use uuidv7() from assets/uuid_v7.js (or newClientId() of official_results_api.js). See .claude/rules/database.md.');
    }

    public function testThePatternCatchesTheRandomGenerators(): void
    {
        self::assertSame(1, preg_match(self::PATTERN, 'SELECT gen_random_uuid(), player_id FROM player'));
        self::assertSame(1, preg_match(self::PATTERN, 'VALUES (uuid_generate_v4(), :name)'));
        self::assertSame(1, preg_match(self::PATTERN, 'SELECT uuidv4()'));

        self::assertSame(0, preg_match(self::PATTERN, 'SELECT gen_uuid_v7(), player_id FROM player'));
        self::assertSame(0, preg_match(self::PATTERN, '$id = Uuid::uuid7();'));
    }

    public function testThePhpPatternCatchesTheRandomGenerators(): void
    {
        self::assertSame(1, preg_match(self::PHP_PATTERN, '$id = Uuid::uuid4();'));
        self::assertSame(1, preg_match(self::PHP_PATTERN, '$id = \Ramsey\Uuid\Uuid::uuid4 ();'));
        self::assertSame(1, preg_match(self::PHP_PATTERN, '$id = $this->uuidFactory->uuid4();'));
        self::assertSame(1, preg_match(self::PHP_PATTERN, '$id = Uuid::v4();'));
        self::assertSame(1, preg_match(self::PHP_PATTERN, '$id = new UuidV4();'));

        self::assertSame(0, preg_match(self::PHP_PATTERN, '$id = Uuid::uuid7();'));
        self::assertSame(0, preg_match(self::PHP_PATTERN, '$id = Uuid::v7();'));
        self::assertSame(0, preg_match(self::PHP_PATTERN, 'return Uuid::uuid5(self::IDEMPOTENCY_NAMESPACE, $playerId . \'|\' . $key);'));
    }

    public function testTheBrowserPatternCatchesTheRandomGenerator(): void
    {
        self::assertSame(1, preg_match(self::BROWSER_PATTERN, 'const id = crypto.randomUUID();'));
        self::assertSame(1, preg_match(self::BROWSER_PATTERN, 'this.newId = newId ?? (() => globalThis.crypto.randomUUID());'));
        self::assertSame(1, preg_match(self::BROWSER_PATTERN, "if (typeof crypto.randomUUID === 'function') {"));

        self::assertSame(0, preg_match(self::BROWSER_PATTERN, 'const id = uuidv7();'));
        self::assertSame(0, preg_match(self::BROWSER_PATTERN, 'crypto.getRandomValues(bytes);'));
    }

    /**
     * @param list<string> $extensions
     * @return list<string>
     */
    private function filesMatching(string $directory, array $extensions, string $pattern): array
    {
        $offending = [];
        $root = (string) realpath($directory);

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!in_array($file->getExtension(), $extensions, true)) {
                continue;
            }

            if (preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                $offending[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        sort($offending);

        return $offending;
    }
}
