<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ApprovedPuzzleDecision;
use SpeedPuzzling\Web\Results\BrandSuggestion;
use SpeedPuzzling\Web\Results\PendingPuzzleApproval;
use SpeedPuzzling\Web\Results\PuzzleDuplicateCandidate;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;

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

    public function countApproved(): int
    {
        $count = $this->database->fetchOne(
            'SELECT COUNT(*) FROM puzzle_moderation_decision WHERE action = :action',
            ['action' => PuzzleModerationAction::PuzzleApproved->value],
        );
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
     * @return list<ApprovedPuzzleDecision>
     */
    public function recentlyApproved(int $page = 1): array
    {
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    d.decided_at,
    d.decided_by_id,
    d.decided_by_name,
    d.decided_by_code,
    d.puzzle_id,
    COALESCE(p.name, d.puzzle_name) AS puzzle_name,
    p.id IS NOT NULL AS puzzle_exists,
    p.image,
    p.pieces_count,
    m.name AS manufacturer_name
FROM puzzle_moderation_decision d
LEFT JOIN puzzle p ON p.id = d.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE d.action = :action
ORDER BY d.decided_at DESC, d.id DESC
LIMIT :limit OFFSET :offset
SQL,
            [
                'action' => PuzzleModerationAction::PuzzleApproved->value,
                'limit' => self::PAGE_SIZE,
                'offset' => (max(1, $page) - 1) * self::PAGE_SIZE,
            ],
        );

        return array_map(ApprovedPuzzleDecision::fromDatabaseRow(...), $rows);
    }

    /**
     * Puzzles that are probably the same product: any shared EAN (a puzzle may
     * hold several, comma-separated), or the same piece count and a similar name
     * (trigram, served by custom_puzzle_name_trgm). ~30 ms on production data.
     *
     * @return list<PuzzleDuplicateCandidate>
     */
    public function possibleDuplicates(string $puzzleId, int $limit = 5): array
    {
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
WITH src AS (
    SELECT
        id,
        name,
        pieces_count,
        array_remove(string_to_array(regexp_replace(COALESCE(ean, ''), '\s', '', 'g'), ','), '') AS eans
    FROM puzzle
    WHERE id = :puzzleId
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
    (array_remove(string_to_array(regexp_replace(COALESCE(p.ean, ''), '\s', '', 'g'), ','), '') && src.eans) AS same_ean
FROM puzzle p
CROSS JOIN src
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = p.id
WHERE p.id <> src.id
    AND (
        (cardinality(src.eans) > 0 AND p.ean IS NOT NULL
            AND array_remove(string_to_array(regexp_replace(p.ean, '\s', '', 'g'), ','), '') && src.eans)
        OR (p.pieces_count = src.pieces_count AND p.name % src.name)
    )
ORDER BY same_ean DESC, similarity(p.name, src.name) DESC, p.approved DESC
LIMIT :limit
SQL,
            ['puzzleId' => $puzzleId, 'limit' => $limit],
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
