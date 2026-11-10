<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\RelatedPuzzle;
use SpeedPuzzling\Web\Results\RelatedPuzzles;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

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

    /**
     * How long a page keeps its choice: the module is shown to guests - mostly crawlers, spread over ~40k puzzles × 6
     * locales and days - so a short TTL would mostly miss. A stale choice only means a newly added, revealed or
     * now more solved puzzle shows up later; everything else is checked on every read (forPuzzleCached()).
     */
    private const int CACHE_TTL_SECONDS = 7 * 24 * 3600;

    /**
     * Up to a day more, so the entries a crawl wave stored do not expire together
     */
    private const int CACHE_TTL_JITTER_SECONDS = 24 * 3600;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private CacheInterface $relatedPuzzlesCache,
    ) {
    }

    /**
     * The same module as forPuzzle() - what the puzzle page shows - with the choice (which puzzles, in which order,
     * and the scope) cached per page for CACHE_TTL_SECONDS and the chosen rows read fresh by id on every view: a new
     * name or picture (or a hidden picture) shows at once. When a chosen puzzle may not be shown any more (deleted,
     * merged away, unapproved, hidden, moved to another brand or size) the choice is computed again, so the page
     * never shows fewer or other puzzles than forPuzzle() would. ~0.4 kB per page in the cache.
     */
    public function forPuzzleCached(string $manufacturerId, int $piecesCount, string $puzzleId): RelatedPuzzles
    {
        // The brand and size in the key: a puzzle moved to another brand or size never gets the old combination's choice
        $key = 'related_v1_' . sha1(strtolower($puzzleId) . '|' . strtolower($manufacturerId) . '|' . $piecesCount);
        $computed = null;

        $compute = function (ItemInterface $item) use ($manufacturerId, $piecesCount, $puzzleId, &$computed): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS + random_int(0, self::CACHE_TTL_JITTER_SECONDS));
            $computed = $this->forPuzzle($manufacturerId, $piecesCount, $puzzleId);

            return self::choiceOf($computed);
        };

        /** @var array{same: bool, ids: list<string>} $choice */
        $choice = $this->relatedPuzzlesCache->get($key, $compute);

        // Just computed: nothing to read again
        if ($computed instanceof RelatedPuzzles) {
            return $computed;
        }

        $puzzles = $this->chosen($choice, $manufacturerId, $piecesCount);

        if ($puzzles !== null) {
            return new RelatedPuzzles(samePiecesCount: $choice['same'], puzzles: $puzzles);
        }

        // A chosen puzzle may not be shown any more: choose again, whatever the entry's age (beta INF = recompute)
        $fresh = $this->forPuzzle($manufacturerId, $piecesCount, $puzzleId);
        $this->relatedPuzzlesCache->get($key, static function (ItemInterface $item) use ($fresh): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS + random_int(0, self::CACHE_TTL_JITTER_SECONDS));

            return self::choiceOf($fresh);
        }, INF);

        return $fresh;
    }

    /**
     * @return array{same: bool, ids: list<string>}
     */
    private static function choiceOf(RelatedPuzzles $related): array
    {
        return [
            'same' => $related->samePiecesCount,
            'ids' => array_map(static fn (RelatedPuzzle $puzzle): string => $puzzle->puzzleId, $related->puzzles),
        ];
    }

    /**
     * The chosen puzzles in the chosen order, read by id under the rules forPuzzle() chose them by - null when any of
     * them fails one now.
     *
     * @param array{same: bool, ids: list<string>} $choice
     *
     * @return null|list<RelatedPuzzle>
     */
    private function chosen(array $choice, string $manufacturerId, int $piecesCount): null|array
    {
        if ($choice['ids'] === []) {
            return [];
        }

        $rows = $this->database
            ->executeQuery(<<<'SQL'
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
    puzzle.pieces_count,
    COALESCE(puzzle_statistics.solved_times_count, 0) AS solved_times
FROM puzzle
LEFT JOIN puzzle_statistics ON puzzle_statistics.puzzle_id = puzzle.id
WHERE puzzle.id IN (:ids)
    AND puzzle.manufacturer_id = :manufacturerId
    AND (:samePieces = false OR puzzle.pieces_count = :piecesCount)
    AND puzzle.approved = true
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
SQL, [
                'ids' => $choice['ids'],
                'manufacturerId' => $manufacturerId,
                'samePieces' => $choice['same'] ? 'true' : 'false',
                'piecesCount' => $piecesCount,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ], [
                'ids' => ArrayParameterType::STRING,
            ])
            ->fetchAllAssociative();

        $byId = [];

        foreach ($rows as $row) {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_image: null|string,
             *     puzzle_image_ratio: null|string,
             *     pieces_count: int,
             *     solved_times: int,
             * } $row
             */
            $byId[$row['puzzle_id']] = RelatedPuzzle::fromDatabaseRow($row);
        }

        $puzzles = [];

        foreach ($choice['ids'] as $id) {
            if (!isset($byId[$id])) {
                return null;
            }

            $puzzles[] = $byId[$id];
        }

        return $puzzles;
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
