<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PendingPuzzleProposal;

readonly final class GetPendingPuzzleProposals
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function hasPendingForPuzzle(string $puzzleId): bool
    {
        $query = <<<SQL
SELECT EXISTS (
    SELECT 1 FROM puzzle_change_request
    WHERE puzzle_id = :puzzleId AND status = 'pending'
    UNION ALL
    SELECT 1 FROM puzzle_merge_request
    WHERE status = 'pending'
      AND (source_puzzle_id = :puzzleId OR reported_duplicate_puzzle_ids::jsonb @> :puzzleIdJson::jsonb)
) as has_pending
SQL;

        $result = $this->database->fetchOne($query, [
            'puzzleId' => $puzzleId,
            'puzzleIdJson' => json_encode([$puzzleId]),
        ]);

        return $result === true;
    }

    /**
     * @return array<PendingPuzzleProposal>
     */
    public function forPuzzle(string $puzzleId): array
    {
        $query = <<<SQL
SELECT
    pcr.id,
    'change_request' as type,
    pcr.submitted_at,
    reporter.name as reporter_name,
    reporter.code as reporter_code,
    CONCAT_WS(', ',
        CASE WHEN pcr.proposed_name IS NOT NULL AND pcr.proposed_name != pcr.original_name THEN 'Name' END,
        CASE WHEN pcr.proposed_manufacturer_id IS NOT NULL AND pcr.proposed_manufacturer_id != pcr.original_manufacturer_id THEN 'Manufacturer' END,
        CASE WHEN pcr.proposed_pieces_count IS NOT NULL AND pcr.proposed_pieces_count != pcr.original_pieces_count THEN 'Pieces' END,
        CASE WHEN pcr.proposed_ean IS NOT NULL AND pcr.proposed_ean != pcr.original_ean THEN 'EAN' END,
        CASE WHEN pcr.proposed_identification_number IS NOT NULL AND pcr.proposed_identification_number != pcr.original_identification_number THEN 'Brand Code' END,
        CASE WHEN pcr.proposed_image IS NOT NULL THEN 'Image' END
    ) as summary
FROM puzzle_change_request pcr
LEFT JOIN player reporter ON reporter.id = pcr.reporter_id
WHERE pcr.puzzle_id = :puzzleId AND pcr.status = 'pending'

UNION ALL

SELECT
    pmr.id,
    'merge_request' as type,
    pmr.submitted_at,
    reporter.name as reporter_name,
    reporter.code as reporter_code,
    'Merge ' || (jsonb_array_length(pmr.reported_duplicate_puzzle_ids::jsonb) + 1)::text || ' puzzles' as summary
FROM puzzle_merge_request pmr
LEFT JOIN player reporter ON reporter.id = pmr.reporter_id
WHERE pmr.status = 'pending'
  AND (pmr.source_puzzle_id = :puzzleId OR pmr.reported_duplicate_puzzle_ids::jsonb @> :puzzleIdJson::jsonb)

ORDER BY submitted_at DESC
SQL;

        $rows = $this->database->fetchAllAssociative($query, [
            'puzzleId' => $puzzleId,
            'puzzleIdJson' => json_encode([$puzzleId]),
        ]);

        return array_map(
            static fn(array $row): PendingPuzzleProposal => PendingPuzzleProposal::fromDatabaseRow($row),
            $rows,
        );
    }
}
