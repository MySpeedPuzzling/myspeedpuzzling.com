<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit\TimeProposals;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;

/**
 * The Results Cup's Final as a `pick` round (competition toolkit v2 C2 tests): solve 2 of 3, in the organiser's order
 * A (a secret puzzle - manual reveal, hidden from everybody), "Blue box" (the fixture's own Puzzle 6000, labelled) and
 * C (a public one).
 */
trait PickFinalScenario
{
    private const string PICK_SECRET = '018d0020-0000-0000-0000-00000000c201';
    private const string PICK_PUBLIC = '018d0020-0000-0000-0000-00000000c203';
    private const string PICK_SECRET_NAME = 'Puzzle 12';

    /**
     * @return string the fixture's own round puzzle of the Final ("Blue box", place 2)
     */
    private static function pickFinal(Connection $database, int $toSolve = 2): string
    {
        $fixtureRow = $database->fetchOne('SELECT id FROM competition_round_puzzle WHERE round_id = :round', ['round' => OfficialResultsFixture::ROUND_FINAL]);
        assert(is_string($fixtureRow));

        $database->insert('competition_round_puzzle', [
            'id' => self::PICK_SECRET,
            'round_id' => OfficialResultsFixture::ROUND_FINAL,
            'puzzle_id' => PuzzleFixture::PUZZLE_1500_01,
            'hide_until_round_starts' => true,
            'hide_mode' => 'entirely',
            'reveal_mode' => 'manual',
            'hides_everywhere' => false,
            'position' => 1,
        ], ['hide_until_round_starts' => 'boolean', 'hides_everywhere' => 'boolean']);
        $database->insert('competition_round_puzzle', [
            'id' => self::PICK_PUBLIC,
            'round_id' => OfficialResultsFixture::ROUND_FINAL,
            'puzzle_id' => PuzzleFixture::PUZZLE_2000,
            'hide_until_round_starts' => false,
            'reveal_mode' => 'automatic',
            'hides_everywhere' => false,
            'position' => 3,
        ], ['hide_until_round_starts' => 'boolean', 'hides_everywhere' => 'boolean']);
        $database->executeStatement(
            "UPDATE competition_round_puzzle SET position = 2, label = 'Blue box' WHERE id = :id",
            ['id' => $fixtureRow],
        );
        $database->executeStatement(
            "UPDATE competition_round SET puzzle_format = 'pick', puzzles_to_solve = :k WHERE id = :round",
            ['k' => $toSolve, 'round' => OfficialResultsFixture::ROUND_FINAL],
        );

        return $fixtureRow;
    }
}
