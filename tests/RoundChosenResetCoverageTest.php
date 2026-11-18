<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `puzzle_solving_time.round_chosen` marks a round the player named (docs/features/competitions-management/
 * shared-puzzles-and-divisions.md A4) - it never outlives the round link. Every SQL statement in src/ that sets
 * `competition_round_id` to NULL (or to a CASE that can be NULL) must set `round_chosen` in the same statement, or be
 * listed here with why. Entity writes go through PuzzleSolvingTime::changeCompetitionRound() / roundResolved(), which
 * keep the two together.
 */
final class RoundChosenResetCoverageTest extends TestCase
{
    /**
     * File => why a statement there clears competition_round_id without round_chosen (none today)
     *
     * @return array<string, string>
     */
    private static function allowed(): array
    {
        return [];
    }

    public function testEveryStatementClearingTheRoundLinkResetsTheChoice(): void
    {
        $offending = [];
        $found = 0;

        foreach (self::sqlStrings() as $path => $strings) {
            foreach ($strings as $sql) {
                if (preg_match('/\bcompetition_round_id\s*=\s*(NULL\b|CASE\b)/i', $sql) !== 1) {
                    continue;
                }

                $found++;

                if (preg_match('/\bround_chosen\b/', $sql) !== 1 && array_key_exists($path, self::allowed()) === false) {
                    $offending[] = $path;
                }
            }
        }

        // The scanner itself works: the delete handlers, the convert handler and both reconcilers clear the link
        self::assertGreaterThanOrEqual(6, $found);
        self::assertSame([], array_values(array_unique($offending)), 'These statements clear competition_round_id but leave round_chosen - set `round_chosen = false` in the same statement (or list the file in allowed() with why)');
    }

    /**
     * Every string literal and heredoc/nowdoc of each PHP file under src/, a heredoc with interpolations joined into one
     *
     * @return iterable<string, list<string>>
     */
    private static function sqlStrings(): iterable
    {
        $root = dirname(__DIR__);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $strings = [];
            $heredoc = null;

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) === false) {
                    if ($heredoc !== null) {
                        $heredoc .= $token;
                    }

                    continue;
                }

                [$kind, $text] = $token;

                if ($kind === T_START_HEREDOC) {
                    $heredoc = '';
                } elseif ($kind === T_END_HEREDOC) {
                    $strings[] = (string) $heredoc;
                    $heredoc = null;
                } elseif ($heredoc !== null) {
                    $heredoc .= $text;
                } elseif ($kind === T_CONSTANT_ENCAPSED_STRING) {
                    $strings[] = $text;
                }
            }

            yield substr($file->getPathname(), strlen($root) + 1) => $strings;
        }
    }
}
