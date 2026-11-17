<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;

/**
 * The Results Cup's Group B with three puzzles (competition toolkit A2 tests): two added here with fixed ids that sort
 * before the fixture's own row (a uuid7 of the fixture run), so the round's add order is SECRET (A), PUBLIC (B), then
 * the fixture's Puzzle 17 (C). SECRET is kept secret with a manual reveal - hidden from everybody.
 */
trait RoundPuzzleFormatSetup
{
    private const string ROUND = OfficialResultsFixture::ROUND_GROUP_B;
    private const string SECRET = '018d0020-0000-0000-0000-00000000a201';
    private const string PUBLIC = '018d0020-0000-0000-0000-00000000a202';
    private const string SECRET_NAME = 'Puzzle 12';
    private const string PUBLIC_NAME = 'Puzzle 14';

    private static function addRoundPuzzles(Connection $database): string
    {
        $database->insert('competition_round_puzzle', [
            'id' => self::SECRET,
            'round_id' => self::ROUND,
            'puzzle_id' => PuzzleFixture::PUZZLE_1500_01,
            'hide_until_round_starts' => true,
            'hide_mode' => 'entirely',
            'reveal_mode' => 'manual',
            'hides_everywhere' => false,
        ], ['hide_until_round_starts' => 'boolean', 'hides_everywhere' => 'boolean']);
        $database->insert('competition_round_puzzle', [
            'id' => self::PUBLIC,
            'round_id' => self::ROUND,
            'puzzle_id' => PuzzleFixture::PUZZLE_2000,
            'hide_until_round_starts' => false,
            'reveal_mode' => 'automatic',
            'hides_everywhere' => false,
        ], ['hide_until_round_starts' => 'boolean', 'hides_everywhere' => 'boolean']);

        $fixtureRow = $database->fetchOne(
            'SELECT id FROM competition_round_puzzle WHERE round_id = :round AND id NOT IN (:secret, :public)',
            ['round' => self::ROUND, 'secret' => self::SECRET, 'public' => self::PUBLIC],
        );
        assert(is_string($fixtureRow));

        return $fixtureRow;
    }

    private static function makePick(Connection $database, int $toSolve): void
    {
        $database->executeStatement(
            "UPDATE competition_round SET puzzle_format = 'pick', puzzles_to_solve = :k WHERE id = :round",
            ['k' => $toSolve, 'round' => self::ROUND],
        );
    }
}
