<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleTextSearch;

/**
 * The catalogue puzzles an import's distinct puzzles could be (docs/features/data-import/pairing.md "The statements")
 * - set-based: one statement for all the puzzles handed in, never one per puzzle. What a name, a barcode and a brand
 * code match is PuzzleTextSearch's (the stored search keys); this class only joins many texts to them.
 *
 * **Who may be a candidate** is one rule, sqlCandidate(): never a puzzle hidden from the site or hiding its picture
 * and codes (`hide_until` / `hide_image_until` in the future - a competition's secret puzzle, a placeholder), never a
 * puzzle that waits for approval unless the player added it themselves.
 */
readonly final class GetImportPuzzleCandidates
{
    /** How many puzzles a wanted puzzle's names, its barcodes or its brand codes may bring: "Cats" without a piece count is hundreds */
    public const int MAX_PER_TEXT = 20;
    public const int MAX_SIMILAR = 5;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * True for a puzzle that may be offered to the player. Bind :now ('Y-m-d H:i:s') and :playerId.
     */
    public static function sqlCandidate(string $alias): string
    {
        return "(({$alias}.hide_until IS NULL OR {$alias}.hide_until <= :now::timestamp)"
            . " AND ({$alias}.hide_image_until IS NULL OR {$alias}.hide_image_until <= :now::timestamp)"
            . " AND ({$alias}.approved OR {$alias}.added_by_user_id = :playerId))";
    }

    /**
     * Every exact hit of every wanted puzzle, in one statement: a whole name of the catalogue puzzle (with the same
     * piece count when the file has one), a barcode, a brand code, the file's own puzzle id (following a merge).
     * `isPlayers` = the player solved or owns the catalogue puzzle - among several candidates those come first; a
     * result counts whatever its state (a suspicious result is still the player's own solve).
     *
     * **Equality joins, no trigram index** (measured on production, 46,427 puzzles: 143 ms for 5,000 wanted puzzles;
     * the same through `LIKE` and the trigram indexes ran into the 20 s timeout at 3,000): the catalogue's keys are
     * split into their lines once (PuzzleTextSearch::sqlNameLines() / sqlCodeLines()) and hash-joined with the wanted
     * texts, which are written exactly as the lines are. One shape for every size of import.
     *
     * **Who may be a candidate and whether the brand agrees is settled before the cap** (MAX_PER_TEXT per wanted
     * puzzle and kind of lookup, the brand's own puzzles first): a cut list must never leave one puzzle looking
     * like the only one. A lookup that found more than it may bring is `capped` - the rules never say "found" then.
     *
     * @param list<array{id: string, pieces: null|int, manufacturerId: null|string, ownId: null|string, names: list<array{line: string, how: string}>, eans: list<string>, codes: list<string>}> $wanted
     *        texts of PuzzleTextSearch::nameLine() / barcodeLine() / brandCodeLine(); a name's `how` is what its
     *        hits are called (`name`, `name_without_brackets`, `name_short`)
     *
     * @return list<array{importPuzzleId: string, puzzleId: string, how: string, pieces: int, manufacturerId: null|string, isPlayers: bool, capped: bool}>
     */
    public function exactHits(array $wanted, string $playerId, DateTimeImmutable $now): array
    {
        if ($wanted === []) {
            return [];
        }

        $candidate = self::sqlCandidate('puzzle');
        $nameLines = PuzzleTextSearch::sqlNameLines();
        $codeLines = PuzzleTextSearch::sqlCodeLines();
        $limit = self::MAX_PER_TEXT;

        $query = <<<SQL
WITH wanted AS MATERIALIZED (
    SELECT *
    FROM jsonb_to_recordset(CAST(:wanted AS JSONB)) AS wanted(id UUID, pieces INT, "manufacturerId" UUID, "ownId" UUID, names JSONB, eans JSONB, codes JSONB)
),
wanted_names AS MATERIALIZED (
    SELECT DISTINCT wanted.id, wanted.pieces, wanted."manufacturerId" AS manufacturer_id, name.line, name.how
    FROM wanted
    CROSS JOIN LATERAL jsonb_to_recordset(wanted.names) AS name(line TEXT, how TEXT)
),
wanted_codes AS MATERIALIZED (
    SELECT DISTINCT wanted.id, wanted."manufacturerId" AS manufacturer_id, 'e' AS tag, barcode.code
    FROM wanted
    CROSS JOIN LATERAL jsonb_array_elements_text(wanted.eans) AS barcode(code)

    UNION ALL

    SELECT DISTINCT wanted.id, wanted."manufacturerId", 'c', brand_code.code
    FROM wanted
    CROSS JOIN LATERAL jsonb_array_elements_text(wanted.codes) AS brand_code(code)
),
catalogue_names AS MATERIALIZED (
    {$nameLines}
),
catalogue_codes AS MATERIALIZED (
    {$codeLines}
),
hits AS (
    -- Who may be a candidate, and whether the brand agrees, is settled BEFORE the cap: the brand's own puzzles come
    -- first, and a kind of lookup that found more than it may bring says so (`capped`) - never "found" then
    SELECT by_name.id, by_name.puzzle_id, by_name.how, by_name.total > {$limit} AS capped
    FROM (
        SELECT
            wanted_names.id,
            wanted_names.how,
            puzzle.id AS puzzle_id,
            row_number() OVER name_hits AS position,
            COUNT(*) OVER (PARTITION BY wanted_names.id, wanted_names.how) AS total
        FROM wanted_names
        INNER JOIN catalogue_names
            ON catalogue_names.line = wanted_names.line
            AND (wanted_names.pieces IS NULL OR catalogue_names.pieces_count = wanted_names.pieces)
        INNER JOIN puzzle ON puzzle.id = catalogue_names.id
        WHERE {$candidate}
        WINDOW name_hits AS (
            PARTITION BY wanted_names.id, wanted_names.how
            ORDER BY (wanted_names.manufacturer_id IS NOT NULL AND puzzle.manufacturer_id = wanted_names.manufacturer_id) DESC, puzzle.id
        )
    ) by_name
    WHERE by_name.position <= {$limit}

    UNION ALL

    SELECT by_code.id, by_code.puzzle_id, CASE WHEN by_code.tag = 'e' THEN 'barcode' ELSE 'brand_code' END, by_code.total > {$limit}
    FROM (
        SELECT
            wanted_codes.id,
            wanted_codes.tag,
            puzzle.id AS puzzle_id,
            row_number() OVER code_hits AS position,
            COUNT(*) OVER (PARTITION BY wanted_codes.id, wanted_codes.tag) AS total
        FROM wanted_codes
        INNER JOIN catalogue_codes
            ON catalogue_codes.code = wanted_codes.code
            AND catalogue_codes.tag = wanted_codes.tag
        INNER JOIN puzzle ON puzzle.id = catalogue_codes.id
        WHERE {$candidate}
        WINDOW code_hits AS (
            PARTITION BY wanted_codes.id, wanted_codes.tag
            ORDER BY (wanted_codes.manufacturer_id IS NOT NULL AND puzzle.manufacturer_id = wanted_codes.manufacturer_id) DESC, puzzle.id
        )
    ) by_code
    WHERE by_code.position <= {$limit}

    UNION ALL

    SELECT wanted.id, COALESCE(puzzle_redirect.survivor_puzzle_id, wanted."ownId"), 'own_id', FALSE
    FROM wanted
    LEFT JOIN puzzle_redirect ON puzzle_redirect.old_puzzle_id = wanted."ownId"
    WHERE wanted."ownId" IS NOT NULL
)
SELECT
    hits.id AS import_puzzle_id,
    hits.puzzle_id,
    hits.how,
    BOOL_OR(hits.capped) AS capped,
    puzzle.pieces_count,
    puzzle.manufacturer_id,
    (
        EXISTS (SELECT 1 FROM puzzle_solving_time WHERE puzzle_solving_time.player_id = :playerId AND puzzle_solving_time.puzzle_id = puzzle.id)
        OR EXISTS (SELECT 1 FROM collection_item WHERE collection_item.player_id = :playerId AND collection_item.puzzle_id = puzzle.id)
    ) AS is_players
FROM hits
INNER JOIN puzzle ON puzzle.id = hits.puzzle_id
WHERE {$candidate}
GROUP BY hits.id, hits.puzzle_id, hits.how, puzzle.id
ORDER BY hits.id, hits.puzzle_id, hits.how
SQL;

        /** @var list<array{import_puzzle_id: string, puzzle_id: string, how: string, capped: bool, pieces_count: int, manufacturer_id: null|string, is_players: bool}> $rows */
        $rows = $this->database->executeQuery($query, [
            'wanted' => json_encode($wanted, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'playerId' => $playerId,
            'now' => $now->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'importPuzzleId' => $row['import_puzzle_id'],
            'puzzleId' => $row['puzzle_id'],
            'how' => $row['how'],
            'pieces' => $row['pieces_count'],
            'manufacturerId' => $row['manufacturer_id'],
            'isPlayers' => $row['is_players'],
            'capped' => $row['capped'],
        ], $rows);
    }

    /**
     * The catalogue puzzles with a name similar to each wanted puzzle's - at most MAX_SIMILAR each, the same piece
     * count first, then the same brand, then the most similar. One statement for the chunk: a lookup per wanted
     * puzzle over the names key's trigram index. Run inside a transaction - the index threshold is set for it.
     *
     * @param list<array{id: string, name: string, pieces: null|int, manufacturerId: null|string}> $wanted `name` folded by PuzzleTextSearch::foldedName()
     *
     * @return list<array{importPuzzleId: string, puzzleId: string, pieces: int, manufacturerId: null|string, score: float}>
     */
    public function similarNames(array $wanted, float $similarity, string $playerId, DateTimeImmutable $now): array
    {
        if ($wanted === []) {
            return [];
        }

        $candidate = self::sqlCandidate('puzzle');
        $similar = PuzzleTextSearch::batchSimilarNameCondition('puzzle', 'wanted.name');
        $score = PuzzleTextSearch::nameSimilarity('puzzle', 'wanted.name');
        $limit = self::MAX_SIMILAR;

        $this->database->executeQuery(PuzzleTextSearch::similarNameThresholdStatement($similarity));

        $query = <<<SQL
WITH wanted AS (
    SELECT *
    FROM jsonb_to_recordset(CAST(:wanted AS JSONB)) AS wanted(id UUID, name TEXT, pieces INT, "manufacturerId" UUID)
)
SELECT wanted.id AS import_puzzle_id, found.puzzle_id, found.pieces_count, found.manufacturer_id, found.score
FROM wanted
CROSS JOIN LATERAL (
    SELECT scored.puzzle_id, scored.pieces_count, scored.manufacturer_id, scored.score
    FROM (
        SELECT puzzle.id AS puzzle_id, puzzle.pieces_count, puzzle.manufacturer_id, {$score} AS score
        FROM puzzle
        WHERE {$similar}
            AND {$candidate}
    ) scored
    WHERE scored.score >= :similarity
    ORDER BY
        (wanted.pieces IS NOT NULL AND scored.pieces_count = wanted.pieces) DESC,
        (wanted."manufacturerId" IS NOT NULL AND scored.manufacturer_id = wanted."manufacturerId") DESC,
        scored.score DESC,
        scored.puzzle_id
    LIMIT {$limit}
) found
ORDER BY wanted.id
SQL;

        /** @var list<array{import_puzzle_id: string, puzzle_id: string, pieces_count: int, manufacturer_id: null|string, score: float|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'wanted' => json_encode($wanted, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'similarity' => $similarity,
            'playerId' => $playerId,
            'now' => $now->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'importPuzzleId' => $row['import_puzzle_id'],
            'puzzleId' => $row['puzzle_id'],
            'pieces' => $row['pieces_count'],
            'manufacturerId' => $row['manufacturer_id'],
            'score' => round((float) $row['score'], 3),
        ], $rows);
    }

    /**
     * Which of the puzzles the player may choose - the candidates' rule - with their piece counts.
     *
     * @param list<string> $puzzleIds
     *
     * @return array<string, int> piece count by puzzle id
     */
    public function choosable(array $puzzleIds, string $playerId, DateTimeImmutable $now): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $candidate = self::sqlCandidate('puzzle');

        /** @var array<string, int> $pieces */
        $pieces = $this->database->executeQuery(
            "SELECT puzzle.id, puzzle.pieces_count FROM puzzle WHERE puzzle.id IN (:ids) AND {$candidate}",
            ['ids' => $puzzleIds, 'playerId' => $playerId, 'now' => $now->format('Y-m-d H:i:s')],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        )->fetchAllKeyValue();

        return $pieces;
    }
}
