<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PuzzleMergeReviewCandidate;
use SpeedPuzzling\Web\Results\PuzzleMergeReviewItem;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;

/**
 * Review queue for puzzle merge requests: every request with every puzzle it
 * proposes to merge, in two queries regardless of how many are pending.
 */
readonly final class GetPuzzleMergeReviewQueue
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<PuzzleMergeReviewItem>
     */
    public function pending(int $limit, int $offset = 0): array
    {
        $requestsQuery = <<<SQL
SELECT
    pmr.id,
    pmr.submitted_at,
    pmr.reported_duplicate_puzzle_ids,
    pmr.source_puzzle_id,
    pmr.source_puzzle_name AS stored_source_puzzle_name,
    source_p.name AS source_puzzle_name,
    reporter.name AS reporter_name,
    reporter.code AS reporter_code
FROM puzzle_merge_request pmr
LEFT JOIN puzzle source_p ON source_p.id = pmr.source_puzzle_id
LEFT JOIN player reporter ON reporter.id = pmr.reporter_id
WHERE pmr.status = :status
ORDER BY pmr.submitted_at ASC
LIMIT :limit OFFSET :offset
SQL;

        $requestRows = $this->database->fetchAllAssociative($requestsQuery, [
            'status' => PuzzleReportStatus::Pending->value,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        if ($requestRows === []) {
            return [];
        }

        /** @var array<string, array<string>> $reportedIdsPerRequest */
        $reportedIdsPerRequest = [];
        $allPuzzleIds = [];

        foreach ($requestRows as $row) {
            $requestId = $row['id'];
            assert(is_string($requestId));
            $reportedJson = $row['reported_duplicate_puzzle_ids'];
            assert(is_string($reportedJson));
            /** @var array<string> $reportedIds */
            $reportedIds = json_decode($reportedJson, true) ?? [];

            $reportedIdsPerRequest[$requestId] = $reportedIds;

            foreach ($reportedIds as $puzzleId) {
                $allPuzzleIds[$puzzleId] = $puzzleId;
            }
        }

        $candidatesByPuzzleId = $this->fetchCandidates(array_values($allPuzzleIds));

        $items = [];

        foreach ($requestRows as $row) {
            $requestId = $row['id'];
            assert(is_string($requestId));
            $submittedAt = $row['submitted_at'];
            assert(is_string($submittedAt));

            $candidates = [];
            $missingPuzzleIds = [];

            foreach ($reportedIdsPerRequest[$requestId] as $puzzleId) {
                if (isset($candidatesByPuzzleId[$puzzleId])) {
                    $candidates[] = $candidatesByPuzzleId[$puzzleId];
                } else {
                    $missingPuzzleIds[] = $puzzleId;
                }
            }

            $sourcePuzzleName = is_string($row['source_puzzle_name'])
                ? $row['source_puzzle_name']
                : (is_string($row['stored_source_puzzle_name']) ? $row['stored_source_puzzle_name'] : 'Deleted puzzle');

            $items[] = new PuzzleMergeReviewItem(
                mergeRequestId: $requestId,
                submittedAt: $submittedAt,
                reporterName: is_string($row['reporter_name']) ? $row['reporter_name'] : null,
                reporterCode: is_string($row['reporter_code']) ? $row['reporter_code'] : null,
                sourcePuzzleId: is_string($row['source_puzzle_id']) ? $row['source_puzzle_id'] : null,
                sourcePuzzleName: $sourcePuzzleName,
                candidates: $candidates,
                missingPuzzleIds: $missingPuzzleIds,
            );
        }

        return $items;
    }

    public function countPending(): int
    {
        $count = $this->database->fetchOne(
            'SELECT COUNT(*) FROM puzzle_merge_request WHERE status = :status',
            ['status' => PuzzleReportStatus::Pending->value],
        );

        assert(is_numeric($count));

        return (int) $count;
    }

    /**
     * @param array<string> $puzzleIds
     * @return array<string, PuzzleMergeReviewCandidate>
     */
    private function fetchCandidates(array $puzzleIds): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT
    p.id AS puzzle_id,
    p.name,
    p.alternative_name,
    p.pieces_count,
    p.ean,
    p.identification_number,
    p.image,
    p.approved,
    p.added_at,
    m.id AS manufacturer_id,
    m.name AS manufacturer_name,
    (SELECT COUNT(*) FROM puzzle_solving_time t WHERE t.puzzle_id = p.id) AS solved_times_count,
    (SELECT COUNT(*) FROM collection_item ci WHERE ci.puzzle_id = p.id) AS collection_items_count,
    (SELECT COUNT(*) FROM wish_list_item wli WHERE wli.puzzle_id = p.id) AS wish_list_items_count,
    (SELECT COUNT(*) FROM sell_swap_list_item ssli WHERE ssli.puzzle_id = p.id) AS sell_swap_items_count
FROM puzzle p
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE p.id IN (:puzzleIds)
SQL;

        $rows = $this->database->fetchAllAssociative(
            $query,
            ['puzzleIds' => $puzzleIds],
            ['puzzleIds' => ArrayParameterType::STRING],
        );

        $candidates = [];

        foreach ($rows as $row) {
            $candidate = PuzzleMergeReviewCandidate::fromDatabaseRow($row);
            $candidates[$candidate->puzzleId] = $candidate;
        }

        return $candidates;
    }
}
