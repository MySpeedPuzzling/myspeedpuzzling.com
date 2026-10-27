<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A deleted solving time goes to the graveyard (disaster recovery) and leaves tombstones for API clients - only when
 * it is deleted through PuzzleSolvingTimeRepository::delete() (docs/features/api/solving-time-sync.md). So every file
 * that removes entities and knows PuzzleSolvingTime is listed here with why, and SQL deleting solving times fails
 * the test - a new deletion path is decided, not forgotten.
 */
final class SolvingTimeDeletionCoverageTest extends TestCase
{
    private const array REMOVES_ENTITIES = [
        'src/Repository/PuzzleSolvingTimeRepository.php' => 'the one deletion path: buries the time, writes the tombstones',
        'src/MessageHandler/DeletePlayerHandler.php' => 'account deletion erases the times without a grave (right to erasure) and wipes the player\'s graves and tombstones',
        'src/MessageHandler/ApprovePuzzleMergeRequestHandler.php' => 'removes collection/wishlist/sell-swap/lending rows - solving times are moved onto the survivor, never removed',
    ];

    public function testEveryFileRemovingEntitiesNextToSolvingTimesIsDecided(): void
    {
        $found = [];

        foreach (self::sourceFiles() as $path => $code) {
            if (str_contains($code, '->remove(') && preg_match('/\bPuzzleSolvingTime\b/', $code) === 1) {
                $found[] = $path;
            }
        }

        sort($found);
        $listed = array_keys(self::REMOVES_ENTITIES);
        sort($listed);

        self::assertSame($listed, $found, 'A file removes entities and knows PuzzleSolvingTime: delete solving times through PuzzleSolvingTimeRepository::delete() (graveyard + tombstones), then list the file here with why');
    }

    public function testNoSqlDeletesSolvingTimes(): void
    {
        foreach (self::sourceFiles() as $path => $code) {
            self::assertDoesNotMatchRegularExpression(
                '/DELETE\s+FROM\s+puzzle_solving_time\b|->delete\(\s*PuzzleSolvingTime::class/i',
                $code,
                "{$path} deletes solving times by SQL - they would skip the graveyard and the tombstones",
            );
        }
    }

    /**
     * @return iterable<string, string>
     */
    private static function sourceFiles(): iterable
    {
        $root = dirname(__DIR__);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            yield substr($file->getPathname(), strlen($root) + 1) => (string) file_get_contents($file->getPathname());
        }
    }
}
