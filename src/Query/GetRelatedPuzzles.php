<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\RelatedPuzzle;
use SpeedPuzzling\Web\Results\RelatedPuzzles;

/**
 * The "More {brand} {N}-piece puzzles" module of the puzzle page
 * (docs/features/seo/implementation-plan-2026-10.md, WS-F2).
 *
 * Half of it is the combination's most-solved puzzles, the same on every page;
 * the other half is picked per page - stable for that page, different on the
 * next one - from the puzzles somebody solved, so links reach the long tail of
 * the catalogue instead of the same top six everywhere.
 */
readonly final class GetRelatedPuzzles
{
    public const int POPULAR_COUNT = 3;

    public const int PICKED_COUNT = 3;

    /**
     * The brand × piece count needs this many other visible puzzles to fill the
     * module; with fewer (a combination of less than 7 puzzles) it takes the
     * whole brand instead.
     */
    public const int MINIMUM_SAME_PIECES_PUZZLES = self::POPULAR_COUNT + self::PICKED_COUNT;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * One statement: the scope decision, the most-solved three and the three
     * picked by md5(id || current puzzle id). Approved, visible puzzles only,
     * never the current one.
     */
    public function forPuzzle(string $manufacturerId, int $piecesCount, string $excludePuzzleId): RelatedPuzzles
    {
        $query = <<<SQL
WITH scope AS (
    SELECT COUNT(*) >= :minimumSamePieces AS same_pieces
    FROM puzzle
    WHERE puzzle.manufacturer_id = :manufacturerId
        AND puzzle.pieces_count = :piecesCount
        AND puzzle.id <> :excludePuzzleId
        AND puzzle.approved = true
        AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
),
candidates AS (
    SELECT
        puzzle.id,
        puzzle.name,
        COALESCE(puzzle_statistics.solved_times_count, 0) AS solved_times
    FROM puzzle
    CROSS JOIN scope
    LEFT JOIN puzzle_statistics ON puzzle_statistics.puzzle_id = puzzle.id
    WHERE puzzle.manufacturer_id = :manufacturerId
        AND puzzle.id <> :excludePuzzleId
        AND puzzle.approved = true
        AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
        AND (scope.same_pieces = false OR puzzle.pieces_count = :piecesCount)
),
popular AS (
    SELECT candidates.id, candidates.name, candidates.solved_times
    FROM candidates
    ORDER BY candidates.solved_times DESC, candidates.name, candidates.id
    LIMIT :popularCount
),
picked AS (
    SELECT candidates.id, candidates.name, candidates.solved_times, md5(candidates.id::text || :seed) AS pick_order
    FROM candidates
    WHERE candidates.solved_times > 0
        AND candidates.id NOT IN (SELECT popular.id FROM popular)
    ORDER BY pick_order
    LIMIT :pickedCount
),
chosen AS (
    SELECT popular.id, popular.name, popular.solved_times, 1 AS part, NULL AS pick_order FROM popular
    UNION ALL
    SELECT picked.id, picked.name, picked.solved_times, 2 AS part, picked.pick_order FROM picked
)
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
    puzzle.pieces_count,
    chosen.solved_times,
    scope.same_pieces
FROM chosen
INNER JOIN puzzle ON puzzle.id = chosen.id
CROSS JOIN scope
ORDER BY chosen.part, chosen.pick_order, chosen.solved_times DESC, chosen.name, chosen.id
SQL;

        $rows = $this->database
            ->executeQuery($query, [
                'manufacturerId' => $manufacturerId,
                'piecesCount' => $piecesCount,
                'excludePuzzleId' => $excludePuzzleId,
                'seed' => $excludePuzzleId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'minimumSamePieces' => self::MINIMUM_SAME_PIECES_PUZZLES,
                'popularCount' => self::POPULAR_COUNT,
                'pickedCount' => self::PICKED_COUNT,
            ])
            ->fetchAllAssociative();

        $samePiecesCount = false;
        $puzzles = [];

        foreach ($rows as $row) {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_image: null|string,
             *     puzzle_image_ratio: null|string,
             *     pieces_count: int,
             *     solved_times: int,
             *     same_pieces: bool,
             * } $row
             */
            // The same on every row: whether the combination was big enough
            $samePiecesCount = $row['same_pieces'];
            unset($row['same_pieces']);

            $puzzles[] = RelatedPuzzle::fromDatabaseRow($row);
        }

        return new RelatedPuzzles(
            samePiecesCount: $samePiecesCount,
            puzzles: $puzzles,
        );
    }
}
