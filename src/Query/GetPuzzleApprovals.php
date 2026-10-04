<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\BrandSuggestion;
use SpeedPuzzling\Web\Results\PendingPuzzleApproval;
use SpeedPuzzling\Web\Results\PuzzleDuplicateCandidate;

/**
 * Read side of the puzzle approval queue (docs/features/puzzle-approvals.md).
 * Admin/moderator only, so no blocklist or private-profile filtering: the
 * adding player is shown to the people who review their puzzle.
 */
readonly final class GetPuzzleApprovals
{
    public const int PAGE_SIZE = 50;

    public function __construct(
        private Connection $database,
    ) {
    }

    public function countPending(): int
    {
        $count = $this->database->fetchOne('SELECT COUNT(*) FROM puzzle WHERE approved = false');
        assert(is_int($count));

        return $count;
    }

    /**
     * Newest first, like the change and merge request queues.
     *
     * @return list<PendingPuzzleApproval>
     */
    public function pending(int $page = 1): array
    {
        $rows = $this->database->fetchAllAssociative(
            self::pendingSelect() . <<<SQL
WHERE p.approved = false
ORDER BY p.added_at DESC NULLS LAST, p.id DESC
LIMIT :limit OFFSET :offset
SQL,
            [
                'limit' => self::PAGE_SIZE,
                'offset' => (max(1, $page) - 1) * self::PAGE_SIZE,
            ],
        );

        return array_map(PendingPuzzleApproval::fromDatabaseRow(...), $rows);
    }

    /**
     * Any puzzle, approved or not - the detail page also explains an already
     * approved one (someone was faster, or it was merged in the meantime).
     */
    public function byPuzzleId(string $puzzleId): null|PendingPuzzleApproval
    {
        $row = $this->database->fetchAssociative(
            self::pendingSelect() . 'WHERE p.id = :puzzleId',
            ['puzzleId' => $puzzleId],
        );

        return $row === false ? null : PendingPuzzleApproval::fromDatabaseRow($row);
    }

    /**
     * Similar puzzles in the catalogue - candidates to compare, not duplicates: any shared barcode (a puzzle may
     * hold several; compared as the search keys store them, leading zeros aside - custom_puzzle_search_codes_trgm),
     * or the same piece count and a similar name. Names are compared one by one - the main title and every other
     * name of both puzzles (trigram similarity): the other puzzle's main title through custom_puzzle_name_trgm, its
     * other names on the puzzles of that piece count that have any. A shared barcode first, then the same brand -
     * one title is printed by many brands, so a name match from another brand is usually another puzzle. ~20 ms on
     * production data.
     *
     * @param list<string> $suggestedBrandIds Brands a new brand probably duplicates (brandSuggestions()) - their
     *                                        puzzles count as the same brand
     *
     * @return list<PuzzleDuplicateCandidate>
     */
    public function possibleDuplicates(string $puzzleId, array $suggestedBrandIds = [], int $limit = 6): array
    {
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
WITH src AS MATERIALIZED (
    SELECT
        id,
        pieces_count,
        manufacturer_id,
        ARRAY[name::text] || ARRAY(SELECT other ->> 'name' FROM jsonb_array_elements(alternative_names) AS other) AS names,
        ARRAY(SELECT line FROM unnest(string_to_array(search_codes, chr(10))) AS line WHERE line LIKE 'e:%') AS barcodes
    FROM puzzle
    WHERE id = :puzzleId
),
candidate AS (
    SELECT p.id
    FROM src
    CROSS JOIN unnest(src.barcodes) AS barcode
    INNER JOIN puzzle p ON p.search_codes LIKE '%' || chr(10) || barcode || chr(10) || '%'
    UNION
    -- Per name of the new puzzle, so each one is a lookup in custom_puzzle_name_trgm (OFFSET 0 keeps it one)
    SELECT similar_title.id
    FROM src
    CROSS JOIN unnest(src.names) AS src_name
    CROSS JOIN LATERAL (
        SELECT p.id FROM puzzle p WHERE p.pieces_count = src.pieces_count AND p.name % src_name OFFSET 0
    ) AS similar_title
    UNION
    SELECT p.id
    FROM src
    INNER JOIN puzzle p ON p.pieces_count = src.pieces_count
    WHERE p.alternative_names <> '[]'::jsonb
        AND EXISTS (
            SELECT 1
            FROM jsonb_array_elements(p.alternative_names) AS other
            CROSS JOIN unnest(src.names) AS src_name
            WHERE (other ->> 'name') % src_name
        )
)
SELECT
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.pieces_count,
    p.image,
    p.ean,
    p.identification_number,
    m.name AS manufacturer_name,
    p.approved,
    COALESCE(ps.solved_times_count, 0) AS solved_times,
    EXISTS (SELECT 1 FROM unnest(src.barcodes) AS barcode WHERE p.search_codes LIKE '%' || chr(10) || barcode || chr(10) || '%') AS same_ean,
    (p.manufacturer_id IS NOT DISTINCT FROM src.manufacturer_id) AS same_brand,
    (p.manufacturer_id = ANY(:suggestedBrandIds::uuid[])) AS suggested_brand,
    (
        SELECT MAX(similarity(p_name, src_name))
        FROM unnest(ARRAY[p.name::text] || ARRAY(SELECT other ->> 'name' FROM jsonb_array_elements(p.alternative_names) AS other)) AS p_name
        CROSS JOIN unnest(src.names) AS src_name
    ) AS name_similarity
FROM candidate
INNER JOIN puzzle p ON p.id = candidate.id
CROSS JOIN src
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = p.id
WHERE p.id <> src.id
ORDER BY
    same_ean DESC,
    COALESCE(p.manufacturer_id IS NOT DISTINCT FROM src.manufacturer_id OR p.manufacturer_id = ANY(:suggestedBrandIds::uuid[]), false) DESC,
    name_similarity DESC,
    p.approved DESC
LIMIT :limit
SQL,
            [
                'puzzleId' => $puzzleId,
                'limit' => $limit,
                'suggestedBrandIds' => '{' . implode(',', array_filter($suggestedBrandIds, Uuid::isValid(...))) . '}',
            ],
        );

        return array_map(PuzzleDuplicateCandidate::fromDatabaseRow(...), $rows);
    }

    /**
     * Approved brands a new brand most likely duplicates: a similar name, or an
     * EAN company prefix that matches the puzzle's EAN (the stronger signal -
     * players type the same brand many ways).
     *
     * @return list<BrandSuggestion>
     */
    public function brandSuggestions(string $manufacturerId, null|string $puzzleEan, int $limit = 5): array
    {
        $firstEan = trim(explode(',', $puzzleEan ?? '')[0]);

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
WITH src AS (
    SELECT id, lower(name) AS name FROM manufacturer WHERE id = :manufacturerId
)
SELECT
    m.id AS manufacturer_id,
    m.name AS manufacturer_name,
    (SELECT COUNT(*) FROM puzzle WHERE puzzle.manufacturer_id = m.id) AS puzzles_count,
    (m.ean_prefix IS NOT NULL AND m.ean_prefix <> '' AND :ean <> '' AND :ean LIKE m.ean_prefix || '%') AS ean_prefix_matches
FROM manufacturer m
CROSS JOIN src
WHERE m.approved = true
    AND m.id <> src.id
    AND (
        similarity(lower(m.name), src.name) > 0.3
        OR lower(m.name) LIKE '%' || src.name || '%'
        OR src.name LIKE '%' || lower(m.name) || '%'
        OR (m.ean_prefix IS NOT NULL AND m.ean_prefix <> '' AND :ean <> '' AND :ean LIKE m.ean_prefix || '%')
    )
ORDER BY ean_prefix_matches DESC, similarity(lower(m.name), src.name) DESC
LIMIT :limit
SQL,
            ['manufacturerId' => $manufacturerId, 'ean' => $firstEan, 'limit' => $limit],
        );

        return array_map(BrandSuggestion::fromDatabaseRow(...), $rows);
    }

    private static function pendingSelect(): string
    {
        return <<<SQL
SELECT
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.name_language,
    p.alternative_names,
    p.pieces_count,
    p.image,
    p.ean,
    p.identification_number,
    p.approved,
    m.id AS manufacturer_id,
    m.name AS manufacturer_name,
    m.approved AS manufacturer_approved,
    (SELECT COUNT(*) FROM puzzle mp WHERE mp.manufacturer_id = m.id) AS manufacturer_puzzles_count,
    adder.id AS added_by_id,
    adder.name AS added_by_name,
    adder.code AS added_by_code,
    p.added_at,
    COALESCE(ps.solved_times_count, 0) AS solved_times,
    (p.ean IS NOT NULL AND EXISTS (
        SELECT 1 FROM puzzle other WHERE other.ean = p.ean AND other.id <> p.id
    )) AS has_same_ean_puzzle,
    EXISTS (
        SELECT 1 FROM puzzle_merge_request mr
        WHERE mr.status = 'pending'
            AND (mr.source_puzzle_id = p.id OR jsonb_exists(mr.reported_duplicate_puzzle_ids::jsonb, p.id::text))
    ) AS in_pending_merge_request
FROM puzzle p
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN player adder ON adder.id = p.added_by_user_id
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = p.id

SQL;
    }
}
