<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A relay team's result has no time and no pieces placed of its own (docs/features/event-results-import/
 * solo-and-relays.md §2) - it would read as a relax solve of the puzzle. Jan (2026-11-17): it is the event's, never the
 * puzzle's, so the puzzle's own pages leave it out through one rule, Query\IsRelayResult. Every reader of the puzzle
 * page family - the puzzle's queries (`Query/GetPuzzle*`), its components and its statistics - either uses the rule or
 * is listed here with the reason it does not need it; a new one fails this test until its author has decided.
 */
final class IsRelayResultCoverageTest extends TestCase
{
    /** Readers of the puzzle's pages that leave a relay result out */
    private const array USES_THE_RULE = [
        'Query/GetPuzzleSolvers.php' => 'the puzzle page tabs: the ranked tabs and the unfinished rows read rows with a time or pieces placed only; the relax / unfinished counts under the tabs use the rule',
        'Query/GetPuzzleResultDetail.php' => 'the result detail of the puzzle: a relay result is not found when opened and never listed among the attempts (the profile links the relay\'s round instead)',
        'Services/PuzzleStatisticsCalculator.php' => 'the puzzle\'s statistics (puzzle_statistics: "Completed N×", counts and times on the puzzle page and in catalogue lists)',
    ];

    /** Readers of the puzzle family that need nothing */
    private const array NEEDS_NOTHING = [
        'Query/GetPuzzleSpeedPercentiles.php' => 'timed rows only (`seconds_to_solve`) - a relay result has none',
        'Query/GetPuzzleIdsForSitemap.php' => 'puzzle ids for the sitemap - no figure',
        'Query/GetPuzzleMergeReviewQueue.php' => 'admin: how much a merge moves - every result of the puzzle moves, a relay result too',
        'Query/GetPuzzlePickerSuggestions.php' => 'the viewer\'s own solves of a puzzle - a claimed relay puzzle is solved by the player, which is wanted (both puzzles count as solved)',
    ];

    public function testEveryReaderOfThePuzzleFamilyDecidesAboutARelayResult(): void
    {
        $root = (string) realpath(__DIR__ . '/../src');
        $undecided = [];

        foreach ([...glob($root . '/Query/GetPuzzle*.php') ?: [], ...glob($root . '/Component/Puzzle*.php') ?: [], ...glob($root . '/Component/Chart/Puzzle*.php') ?: [], ...glob($root . '/Services/Puzzle*.php') ?: []] as $path) {
            $code = (string) file_get_contents($path);

            if (str_contains($code, 'puzzle_solving_time') === false) {
                continue;
            }

            $relative = substr($path, strlen($root) + 1);

            if (array_key_exists($relative, self::USES_THE_RULE) === false && array_key_exists($relative, self::NEEDS_NOTHING) === false) {
                $undecided[] = $relative;
            }
        }

        self::assertSame([], $undecided, 'These readers of the puzzle\'s pages do not decide about a relay team\'s result (solo-and-relays.md §2): use IsRelayResult::notRelay(), or list the file in IsRelayResultCoverageTest with the reason.');
    }

    public function testTheListedReadersUseTheRule(): void
    {
        $root = (string) realpath(__DIR__ . '/../src');

        foreach (array_keys(self::USES_THE_RULE) as $file) {
            self::assertStringContainsString('IsRelayResult::', (string) file_get_contents($root . '/' . $file), $file . ' is listed as leaving relay results out');
        }

        foreach (array_keys(self::NEEDS_NOTHING) as $file) {
            self::assertFileExists($root . '/' . $file);
        }
    }
}
