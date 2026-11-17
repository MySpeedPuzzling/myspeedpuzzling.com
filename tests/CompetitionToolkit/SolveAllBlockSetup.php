<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestClock;

/**
 * The Results Cup's Group B as a published back-to-back block (real events W4): "Solve all 2 (order recorded)" -
 * challenge 1 = Puzzle 14 (place 1, "A"), challenge 2 = the fixture's Puzzle 17 (place 2, "B"), both shown to
 * everybody - with per-puzzle splits: Gina 25:00 + 40:00 (3900), Hugo 20:00 + 55:00 (4500), Ivan 30:00 + 53:20 (5000).
 * Challenge 1's ranking: Hugo 1, Gina 2, Ivan 3 - the round's: Gina 1, Hugo 2, Ivan 3.
 */
final class SolveAllBlockSetup
{
    public const string CHALLENGE_ONE = '018d0020-0000-0000-0000-00000000c401';

    public static function seed(Connection $database): string
    {
        $database->executeStatement('UPDATE competition_round_puzzle SET position = 2 WHERE round_id = :round', ['round' => OfficialResultsFixture::ROUND_GROUP_B]);
        $challengeTwo = $database->fetchOne('SELECT id FROM competition_round_puzzle WHERE round_id = :round', ['round' => OfficialResultsFixture::ROUND_GROUP_B]);
        assert(is_string($challengeTwo));

        $database->insert('competition_round_puzzle', [
            'id' => self::CHALLENGE_ONE,
            'round_id' => OfficialResultsFixture::ROUND_GROUP_B,
            'puzzle_id' => PuzzleFixture::PUZZLE_2000,
            'hide_until_round_starts' => false,
            'reveal_mode' => 'automatic',
            'hides_everywhere' => false,
            'position' => 1,
        ], ['hide_until_round_starts' => 'boolean', 'hides_everywhere' => 'boolean']);

        $database->executeStatement(
            "UPDATE competition_round SET puzzle_format = 'pick', puzzles_to_solve = 2, results_published_at = " . TestClock::SQL_NOW . ', results_first_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['id' => OfficialResultsFixture::ROUND_GROUP_B],
        );

        foreach ([
            OfficialResultsFixture::ENTRY_B_GINA => [1500, 3900],
            OfficialResultsFixture::ENTRY_B_HUGO => [1200, 4500],
            OfficialResultsFixture::ENTRY_B_IVAN => [1800, 5000],
        ] as $entryId => [$first, $second]) {
            $database->executeStatement(
                'UPDATE competition_participant_round SET result_puzzles = :puzzles WHERE id = :id',
                ['puzzles' => json_encode([
                    ['roundPuzzleId' => self::CHALLENGE_ONE, 'done' => true, 'atSeconds' => $first],
                    ['roundPuzzleId' => strtolower($challengeTwo), 'done' => true, 'atSeconds' => $second],
                ], JSON_THROW_ON_ERROR), 'id' => $entryId],
            );
        }

        return strtolower($challengeTwo);
    }
}
