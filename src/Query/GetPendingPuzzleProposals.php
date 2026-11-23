<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\MergePuzzleInfo;
use SpeedPuzzling\Web\Results\PendingFieldProposal;
use SpeedPuzzling\Web\Results\PendingProposalLocks;
use SpeedPuzzling\Web\Results\PendingPuzzleProposal;
use SpeedPuzzling\Web\Value\PuzzleProposalChanges;
use SpeedPuzzling\Web\Value\PuzzleProposalField;
use SpeedPuzzling\Web\Value\PuzzleSecrecy;

/**
 * The proposals waiting for a moderator on a puzzle. Several change requests may wait at once as long as they change
 * different fields (locks()): a field a pending change request changes is locked for every other proposal - the
 * "Suggest a change" form, the internal API's - until a moderator decides it; a pending merge request locks every
 * field. The names (main title, its language, other names) never lock: they apply as a diff, so several proposals of
 * them may wait next to each other, next to a full proposal or a merge request. A duplicate report waits while any
 * field is locked (PendingProposalLocks::locksAnyField()).
 */
readonly final class GetPendingPuzzleProposals
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Whether a pending change request (also of the names only) or merge request is about the puzzle - the puzzle page's
     * "pending proposal" badge.
     */
    public function hasPendingForPuzzle(string $puzzleId): bool
    {
        $noSecretPuzzle = GetPuzzleMergeRequests::sqlNoSecretPuzzle();
        $query = <<<SQL
SELECT EXISTS (
    SELECT 1 FROM puzzle_change_request
    WHERE puzzle_id = :puzzleId AND status = 'pending'
    UNION ALL
    SELECT 1 FROM puzzle_merge_request pmr
    WHERE pmr.status = 'pending'
      AND (pmr.source_puzzle_id = :puzzleId OR pmr.reported_duplicate_puzzle_ids::jsonb @> :puzzleIdJson::jsonb)
      AND {$noSecretPuzzle}
) as has_pending
SQL;

        $result = $this->database->fetchOne($query, [
            'puzzleId' => $puzzleId,
            'puzzleIdJson' => json_encode([$puzzleId]),
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        return $result === true;
    }

    /**
     * What a new proposal may not change now: every field a pending change request changes (PuzzleProposalChanges -
     * its proposed value differs from its original; names never lock), every field while a merge request about the
     * puzzle is pending.
     */
    public function locks(string $puzzleId): PendingProposalLocks
    {
        // A merge request involving a secret competition puzzle is in no queue until the reveal - it must not block
        // proposals on a public puzzle meanwhile (forever, with a manual reveal)
        $noSecretPuzzle = GetPuzzleMergeRequests::sqlNoSecretPuzzle();
        $mergeQuery = <<<SQL
SELECT EXISTS (
    SELECT 1 FROM puzzle_merge_request pmr
    WHERE pmr.status = 'pending'
      AND (pmr.source_puzzle_id = :puzzleId OR pmr.reported_duplicate_puzzle_ids::jsonb @> :puzzleIdJson::jsonb)
      AND {$noSecretPuzzle}
) as blocks
SQL;

        $mergeRequestPending = $this->database->fetchOne($mergeQuery, [
            'puzzleId' => $puzzleId,
            'puzzleIdJson' => json_encode([$puzzleId]),
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]) === true;

        $query = <<<SQL
SELECT
    pcr.id,
    pcr.proposed_name,
    pcr.original_name,
    pcr.proposed_manufacturer_id,
    pcr.original_manufacturer_id,
    proposed_manufacturer.name AS proposed_manufacturer_name,
    pcr.proposed_pieces_count,
    pcr.original_pieces_count,
    pcr.proposed_ean,
    pcr.original_ean,
    pcr.proposed_identification_number,
    pcr.original_identification_number,
    pcr.proposed_image,
    pcr.proposed_ean_absent,
    pcr.original_ean_absent
FROM puzzle_change_request pcr
LEFT JOIN manufacturer proposed_manufacturer ON proposed_manufacturer.id = pcr.proposed_manufacturer_id
WHERE pcr.puzzle_id = :puzzleId AND pcr.status = 'pending'
ORDER BY pcr.submitted_at, pcr.id
SQL;

        /** @var list<array{id: string, proposed_name: null|string, original_name: string, proposed_manufacturer_id: null|string, original_manufacturer_id: null|string, proposed_manufacturer_name: null|string, proposed_pieces_count: null|int, original_pieces_count: int, proposed_ean: null|string, original_ean: null|string, proposed_identification_number: null|string, original_identification_number: null|string, proposed_image: null|string, proposed_ean_absent: null|bool, original_ean_absent: bool}> $rows */
        $rows = $this->database->fetchAllAssociative($query, ['puzzleId' => $puzzleId]);

        $waiting = [];

        foreach ($rows as $row) {
            // The names never lock - they are left out
            $changes = new PuzzleProposalChanges(
                proposedName: null,
                originalName: $row['original_name'],
                proposedManufacturerId: $row['proposed_manufacturer_id'],
                originalManufacturerId: $row['original_manufacturer_id'],
                proposedPiecesCount: $row['proposed_pieces_count'],
                originalPiecesCount: $row['original_pieces_count'],
                proposedEan: $row['proposed_ean'],
                originalEan: $row['original_ean'],
                proposedIdentificationNumber: $row['proposed_identification_number'],
                originalIdentificationNumber: $row['original_identification_number'],
                proposedImage: $row['proposed_image'],
                proposedAlternativeNames: null,
                originalAlternativeNames: null,
                proposedNameLanguage: null,
                originalNameLanguage: null,
                proposedEanAbsent: $row['proposed_ean_absent'],
                originalEanAbsent: $row['original_ean_absent'],
            );

            foreach ($changes->lockedFields() as $field) {
                $waiting[$field->value] ??= new PendingFieldProposal(
                    changeRequestId: $row['id'],
                    proposedValue: match ($field) {
                        PuzzleProposalField::Manufacturer => $row['proposed_manufacturer_name'],
                        PuzzleProposalField::PiecesCount => $row['proposed_pieces_count'] !== null ? (string) $row['proposed_pieces_count'] : null,
                        PuzzleProposalField::Ean => $row['proposed_ean'],
                        PuzzleProposalField::IdentificationNumber => $row['proposed_identification_number'],
                        PuzzleProposalField::Image => $row['proposed_image'],
                        default => null,
                    },
                );
            }
        }

        return new PendingProposalLocks($mergeRequestPending, $waiting);
    }

    /**
     * @return array<PendingPuzzleProposal>
     */
    public function forPuzzle(string $puzzleId): array
    {
        // A merge request with a secret competition puzzle in it is nobody's to see before the reveal
        $noSecretPuzzle = GetPuzzleMergeRequests::sqlNoSecretPuzzle();
        $query = <<<SQL
SELECT
    pcr.id,
    'change_request' as type,
    pcr.submitted_at,
    reporter.name as reporter_name,
    reporter.code as reporter_code,
    CONCAT_WS(', ',
        CASE WHEN pcr.proposed_name IS NOT NULL AND pcr.proposed_name IS DISTINCT FROM pcr.original_name THEN 'Name' END,
        CASE WHEN pcr.proposed_alternative_names IS NOT NULL AND pcr.proposed_name_language IS DISTINCT FROM pcr.original_name_language THEN 'Name language' END,
        CASE WHEN pcr.proposed_alternative_names IS NOT NULL AND pcr.proposed_alternative_names IS DISTINCT FROM pcr.original_alternative_names THEN 'Other names' END,
        CASE WHEN pcr.proposed_manufacturer_id IS NOT NULL AND pcr.proposed_manufacturer_id IS DISTINCT FROM pcr.original_manufacturer_id THEN 'Manufacturer' END,
        CASE WHEN pcr.proposed_pieces_count IS NOT NULL AND pcr.proposed_pieces_count IS DISTINCT FROM pcr.original_pieces_count THEN 'Pieces' END,
        CASE WHEN pcr.proposed_ean IS NOT NULL AND pcr.proposed_ean IS DISTINCT FROM pcr.original_ean THEN 'EAN' END,
        CASE WHEN pcr.proposed_identification_number IS NOT NULL AND pcr.proposed_identification_number IS DISTINCT FROM pcr.original_identification_number THEN 'Brand Code' END,
        CASE WHEN pcr.proposed_image IS NOT NULL THEN 'Image' END,
        CASE WHEN pcr.proposed_ean_absent = true AND pcr.original_ean_absent = false THEN 'No barcode on the box' END
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
  AND {$noSecretPuzzle}

ORDER BY submitted_at DESC
SQL;

        $rows = $this->database->fetchAllAssociative($query, [
            'puzzleId' => $puzzleId,
            'puzzleIdJson' => json_encode([$puzzleId]),
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
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
        // Never a secret competition puzzle (its request is filtered above too - this keeps it so for any caller)
        $notSecret = PuzzleSecrecy::sqlNotSecret('p');
        // Solved N times - an unfinished result is not a solve (CountsAsSolve)
        $countsAsSolve = CountsAsSolve::condition('pst');
        $query = <<<SQL
SELECT
    p.id,
    p.name,
    p.pieces_count,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image END AS image,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image_ratio END AS image_ratio,
    m.name as manufacturer_name,
    (SELECT COUNT(*) FROM puzzle_solving_time pst WHERE pst.puzzle_id = p.id AND {$countsAsSolve}) as times_count
FROM puzzle p
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE p.id IN (:puzzleIds)
    AND {$notSecret}
SQL;

        $rows = $this->database->fetchAllAssociative(
            $query,
            ['puzzleIds' => array_values($puzzleIds), 'now' => $this->clock->now()->format('Y-m-d H:i:s')],
            ['puzzleIds' => ArrayParameterType::STRING],
        );

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
                imageRatio: is_numeric($row['image_ratio']) ? (float) $row['image_ratio'] : null,
                manufacturerName: is_string($row['manufacturer_name']) ? $row['manufacturer_name'] : null,
                timesCount: (int) $timesCount,
            );
        }

        return $result;
    }
}
