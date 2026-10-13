<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;

/**
 * Seeds results lists longer than the fixtures' (docs/features/navigation PROF-05b: 50 rows a page, Show all up to 300).
 * Plain SQL on purpose, like LeaderboardSeeding: persisting the entities would dispatch a PuzzleSolved event
 * (statistics, notifications, insights) for every row. DAMA rolls the rows back with the test.
 */
trait PlayerResultsSeeding
{
    /**
     * A new public player with one solo result on each of $puzzles new puzzles (copies of a fixture puzzle without its
     * codes, named "Seeded n"); result n was solved n hours ago in 3,000 + n seconds
     *
     * @return string the player's id
     */
    protected function seedPlayerWithSoloResults(int $puzzles, string $name = 'Seeded Results Puzzler'): string
    {
        $playerId = Uuid::uuid7()->toString();

        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO player (id, code, name, registered_at) VALUES (:id, :code, :name, NOW() - INTERVAL '1 year')",
            ['id' => $playerId, 'code' => 'seeded' . substr(str_replace('-', '', $playerId), -10), 'name' => $name],
        );

        $this->addSoloResults($playerId, $puzzles);

        return $playerId;
    }

    /**
     * $copies new puzzles (copies of a fixture puzzle without its codes, named "Seeded n"), each with one solo result of
     * the player: result n was solved n hours ago in 3,000 + n seconds
     *
     * @return list<string> the new puzzles' ids, by n
     */
    protected function addSoloResults(string $playerId, int $copies, string $puzzleId = PuzzleFixture::PUZZLE_500_01): array
    {
        $database = self::getContainer()->get(Connection::class);

        $database->executeStatement('DROP TABLE IF EXISTS seeded_puzzle');
        $database->executeStatement('DROP TABLE IF EXISTS seeded_puzzle_copy');
        $database->executeStatement(
            'CREATE TEMP TABLE seeded_puzzle ON COMMIT DROP AS SELECT puzzle.*, g AS copy FROM puzzle, generate_series(1, :copies) g WHERE puzzle.id = :puzzleId',
            ['copies' => $copies, 'puzzleId' => $puzzleId],
            ['copies' => ParameterType::INTEGER],
        );
        $database->executeStatement("UPDATE seeded_puzzle SET id = gen_random_uuid(), name = 'Seeded ' || copy, ean = NULL, identification_number = NULL, search_codes = ''");
        $database->executeStatement('CREATE TEMP TABLE seeded_puzzle_copy ON COMMIT DROP AS SELECT id, copy FROM seeded_puzzle');
        $database->executeStatement('ALTER TABLE seeded_puzzle DROP COLUMN copy');
        $database->executeStatement('INSERT INTO puzzle SELECT * FROM seeded_puzzle');
        $database->executeStatement(
            <<<SQL
INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt)
SELECT gen_random_uuid(), :playerId, spc.id, 3000 + spc.copy, NOW() - spc.copy * INTERVAL '1 hour', NOW() - spc.copy * INTERVAL '1 hour', true, true
FROM seeded_puzzle_copy spc
SQL,
            ['playerId' => $playerId],
        );

        /** @var list<string> $puzzleIds */
        $puzzleIds = $database->fetchFirstColumn('SELECT id FROM seeded_puzzle_copy ORDER BY copy');

        return $puzzleIds;
    }
}
