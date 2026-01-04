<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\MergePuzzleInfo;
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
    ) as summary,
    NULL as reported_duplicate_puzzle_ids
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
    'Merge ' || jsonb_array_length(pmr.reported_duplicate_puzzle_ids::jsonb)::text || ' puzzles' as summary,
    pmr.reported_duplicate_puzzle_ids
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

        // Collect all puzzle IDs from merge requests to fetch in one query
        $allPuzzleIds = [];
        foreach ($rows as $row) {
            if ($row['type'] === 'merge_request' && is_string($row['reported_duplicate_puzzle_ids'])) {
                /** @var array<string> $puzzleIds */
                $puzzleIds = json_decode($row['reported_duplicate_puzzle_ids'], true) ?? [];
                $allPuzzleIds = array_merge($allPuzzleIds, $puzzleIds);
            }
        }
        $allPuzzleIds = array_unique($allPuzzleIds);

        // Fetch puzzle details if we have any merge requests
        $puzzleDetails = [];
        if (count($allPuzzleIds) > 0) {
            $puzzleDetails = $this->fetchPuzzleDetails($allPuzzleIds);
        }

        return array_map(
            static function (array $row) use ($puzzleDetails): PendingPuzzleProposal {
                $mergePuzzles = [];
                if ($row['type'] === 'merge_request' && is_string($row['reported_duplicate_puzzle_ids'])) {
                    /** @var array<string> $puzzleIds */
                    $puzzleIds = json_decode($row['reported_duplicate_puzzle_ids'], true) ?? [];
                    foreach ($puzzleIds as $pid) {
                        if (isset($puzzleDetails[$pid])) {
                            $mergePuzzles[] = $puzzleDetails[$pid];
                        }
                    }
                }

                return PendingPuzzleProposal::fromDatabaseRow($row, $mergePuzzles);
            },
            $rows,
        );
    }

    /**
     * @param array<string> $puzzleIds
     * @return array<string, MergePuzzleInfo>
     */
    private function fetchPuzzleDetails(array $puzzleIds): array
    {
        $placeholders = implode(',', array_fill(0, count($puzzleIds), '?'));

        $query = <<<SQL
SELECT
    p.id,
    p.name,
    p.pieces_count,
    p.image,
    m.name as manufacturer_name,
    (SELECT COUNT(*) FROM puzzle_solving_time pst WHERE pst.puzzle_id = p.id) as times_count
FROM puzzle p
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE p.id IN ({$placeholders})
SQL;

        $rows = $this->database->fetchAllAssociative($query, array_values($puzzleIds));

        $result = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            assert(is_string($id));
            $name = $row['name'];
            assert(is_string($name));

            $timesCount = $row['times_count'];
            assert(is_numeric($timesCount));

            $result[$id] = new MergePuzzleInfo(
                id: $id,
                name: $name,
                piecesCount: is_int($row['pieces_count']) ? $row['pieces_count'] : null,
                image: is_string($row['image']) ? $row['image'] : null,
                manufacturerName: is_string($row['manufacturer_name']) ? $row['manufacturer_name'] : null,
                timesCount: (int) $timesCount,
            );
        }

        return $result;
    }
}
