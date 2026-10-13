<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\PersonalBest;
use SpeedPuzzling\Web\Results\PlayerOverviewNumbers;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleNames;

/**
 * The figures of a player's Overview (docs/features/navigation README §4.7, PROF-03) in one statement: the fastest solo
 * result per piece-count preset (PiecesRange::PRESETS) and the headline numbers - puzzles solved solo / in pairs / in
 * teams and every result.
 *
 * The numbers count with the WHERE of the Results page's lists (GetPlayerSolvedPuzzles::soloWhere() / groupWhere() and
 * the viewer's HiddenPlayers fragments, the same joins), so they always equal its tab counts for the same viewer
 * (PlayerOverviewNumbersParityTest): a result awaiting verification and a relax result are listed there - and counted
 * here. A personal best is a TIME: never a suspicious one (docs/features/suspicious-times.md), never a relax result;
 * equal times go to the earlier solve, then the lower id.
 */
readonly final class GetPlayerOverviewNumbers
{
    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function forPlayer(string $playerId): PlayerOverviewNumbers
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $soloWhere = GetPlayerSolvedPuzzles::soloWhere('pst');
        $pairWhere = GetPlayerSolvedPuzzles::groupWhere('pst', 'duo');
        $teamWhere = GetPlayerSolvedPuzzles::groupWhere('pst', 'team');
        $soloNotHidden = $this->hiddenPlayers->sqlExclude('pst.player_id');
        $groupNotHidden = $this->hiddenPlayers->sqlExcludeTeam('pst.team');
        $presetOf = self::presetCase('solo.pieces_count');

        // The lists join the puzzle and its brand (INNER): so do the counts. The solo rows are read once (a materialised
        // CTE) for both the numbers and the bests
        $query = <<<SQL
WITH solo AS (
    SELECT pst.id, pst.puzzle_id, pst.seconds_to_solve, pst.suspicious, COALESCE(pst.finished_at, pst.tracked_at) AS solved_at, p.pieces_count
    FROM puzzle_solving_time pst
    INNER JOIN puzzle p ON p.id = pst.puzzle_id
    INNER JOIN manufacturer m ON m.id = p.manufacturer_id
    WHERE {$soloWhere}{$soloNotHidden}
),
pairs AS (
    SELECT pst.puzzle_id
    FROM puzzle_solving_time pst
    INNER JOIN puzzle p ON p.id = pst.puzzle_id
    INNER JOIN manufacturer m ON m.id = p.manufacturer_id
    WHERE {$pairWhere}{$groupNotHidden}
),
teams AS (
    SELECT pst.puzzle_id
    FROM puzzle_solving_time pst
    INNER JOIN puzzle p ON p.id = pst.puzzle_id
    INNER JOIN manufacturer m ON m.id = p.manufacturer_id
    WHERE {$teamWhere}{$groupNotHidden}
),
numbers AS (
    SELECT
        (SELECT COUNT(DISTINCT puzzle_id) FROM solo) AS solo_puzzles,
        (SELECT COUNT(*) FROM solo) AS solo_results,
        (SELECT COUNT(DISTINCT puzzle_id) FROM pairs) AS pair_puzzles,
        (SELECT COUNT(*) FROM pairs) AS pair_results,
        (SELECT COUNT(DISTINCT puzzle_id) FROM teams) AS team_puzzles,
        (SELECT COUNT(*) FROM teams) AS team_results
),
bests AS (
    -- The fastest timed solo result per preset - a 0 s time is no time, a time awaiting verification is no best
    SELECT DISTINCT ON (preset) preset, id, puzzle_id, seconds_to_solve, solved_at
    FROM (
        SELECT solo.*, {$presetOf} AS preset
        FROM solo
        WHERE solo.seconds_to_solve > 0 AND solo.suspicious = false
    ) candidates
    WHERE preset IS NOT NULL
    ORDER BY preset, seconds_to_solve, solved_at, id
)
SELECT
    numbers.solo_puzzles,
    numbers.solo_results,
    numbers.pair_puzzles,
    numbers.pair_results,
    numbers.team_puzzles,
    numbers.team_results,
    bests.preset,
    bests.id AS time_id,
    bests.seconds_to_solve,
    bests.solved_at,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_names AS puzzle_alternative_names,
    puzzle.pieces_count,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
    manufacturer.name AS manufacturer_name
FROM numbers
LEFT JOIN bests ON true
LEFT JOIN puzzle ON puzzle.id = bests.puzzle_id
LEFT JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
ORDER BY bests.preset
SQL;

        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        $presets = PiecesRange::presets();
        $bests = [];
        $first = $rows[0] ?? [];

        foreach ($rows as $row) {
            /**
             * @var array{
             *     preset: null|int,
             *     time_id: null|string,
             *     seconds_to_solve: null|int,
             *     solved_at: null|string,
             *     puzzle_id: null|string,
             *     puzzle_name: null|string,
             *     puzzle_alternative_names: null|string,
             *     pieces_count: null|int,
             *     puzzle_image: null|string,
             *     puzzle_image_ratio: null|float|string,
             *     manufacturer_name: null|string,
             * } $row
             */
            if ($row['preset'] === null || $row['time_id'] === null || $row['puzzle_id'] === null || isset($presets[$row['preset']]) === false) {
                continue;
            }

            $bests[] = new PersonalBest(
                preset: $presets[$row['preset']],
                timeId: $row['time_id'],
                puzzleId: $row['puzzle_id'],
                puzzleName: (string) $row['puzzle_name'],
                puzzleAlternativeNames: PuzzleNames::fromJson($row['puzzle_alternative_names']),
                manufacturerName: (string) $row['manufacturer_name'],
                piecesCount: (int) $row['pieces_count'],
                puzzleImage: $row['puzzle_image'],
                puzzleImageRatio: $row['puzzle_image_ratio'] !== null ? (float) $row['puzzle_image_ratio'] : null,
                seconds: (int) $row['seconds_to_solve'],
                solvedAt: new DateTimeImmutable((string) $row['solved_at']),
            );
        }

        return new PlayerOverviewNumbers(
            soloPuzzles: self::int($first, 'solo_puzzles'),
            pairPuzzles: self::int($first, 'pair_puzzles'),
            teamPuzzles: self::int($first, 'team_puzzles'),
            soloResults: self::int($first, 'solo_results'),
            pairResults: self::int($first, 'pair_results'),
            teamResults: self::int($first, 'team_results'),
            personalBests: $bests,
        );
    }

    /**
     * The preset index (PiecesRange::presets() order) of a piece count, NULL outside every preset - generated from the
     * presets, so a changed chip changes the bests too
     */
    private static function presetCase(string $column): string
    {
        $branches = [];

        foreach (PiecesRange::presets() as $index => $preset) {
            $conditions = [];

            if ($preset->minPieces !== null) {
                $conditions[] = "{$column} >= {$preset->minPieces}";
            }

            if ($preset->maxPieces !== null) {
                $conditions[] = "{$column} <= {$preset->maxPieces}";
            }

            $branches[] = 'WHEN ' . implode(' AND ', $conditions) . " THEN {$index}";
        }

        return 'CASE ' . implode(' ', $branches) . ' END';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
