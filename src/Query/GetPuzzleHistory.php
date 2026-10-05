<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\PuzzleHistoryChange;
use SpeedPuzzling\Web\Results\PuzzleHistoryEntry;
use SpeedPuzzling\Web\Results\PuzzleHistoryPuzzle;
use SpeedPuzzling\Web\Value\PuzzleHistoryEntryKind;

/**
 * A puzzle's history for moderators: every decision about it in the decision log (puzzle_moderation_decision,
 * complete since its backfill), with the details it recorded - the puzzle before and after an edit or an approved
 * change request, a merge's survivor before and after plus what moved (puzzle_merge_audit), the proposal of a
 * change request decided before the log recorded values. Plus EANs written straight from a barcode scan: their
 * approved change request is their only record (LinkEanToPuzzleHandler).
 *
 * Works for a puzzle that no longer exists too - a merge deletes puzzles, their history stays.
 */
readonly final class GetPuzzleHistory
{
    private const array MOVED_RECORD_LABELS = [
        'solvingTimes' => 'solving times',
        'collectionItems' => 'collection items',
        'wishListItems' => 'wish-list items',
        'sellSwapListItems' => 'sell/swap offers',
        'lentPuzzles' => 'lendings',
        'lentPuzzleTransfers' => 'lending transfers',
        'soldSwappedItems' => 'sold/swapped records',
        'competitionRoundPuzzles' => 'event round puzzles',
        'conversations' => 'conversations',
        'stopwatches' => 'stopwatches',
        'tags' => 'tags',
    ];

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<PuzzleHistoryEntry> Newest first
     */
    public function forPuzzle(string $puzzleId): array
    {
        if (Uuid::isValid($puzzleId) === false) {
            return [];
        }

        $entries = [
            ...$this->decisions($puzzleId),
            ...$this->changeRequestsWithoutDecision($puzzleId),
        ];

        // Stable: entries of the same second stay in the order their query gave them
        usort($entries, static fn (PuzzleHistoryEntry $a, PuzzleHistoryEntry $b): int => $b->at <=> $a->at);

        return $entries;
    }

    /**
     * The puzzles' names as they are now - or, for a puzzle that no longer exists, as the decision log last saw it.
     *
     * @param list<string> $puzzleIds
     *
     * @return array<string, string>
     */
    public function knownNames(array $puzzleIds): array
    {
        $puzzleIds = array_values(array_unique(array_filter($puzzleIds, static fn (string $id): bool => Uuid::isValid($id))));

        if ($puzzleIds === []) {
            return [];
        }

        $names = [];

        $rows = $this->database->executeQuery(
            'SELECT id, name FROM puzzle WHERE id IN (:ids)',
            ['ids' => $puzzleIds],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        foreach ($rows as $row) {
            if (is_string($row['id']) && is_string($row['name'])) {
                $names[$row['id']] = $row['name'];
            }
        }

        $missing = array_values(array_diff($puzzleIds, array_keys($names)));

        if ($missing === []) {
            return $names;
        }

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT DISTINCT ON (puzzle_id) puzzle_id, puzzle_name
FROM puzzle_moderation_decision
WHERE puzzle_id IN (:ids) AND puzzle_name IS NOT NULL
ORDER BY puzzle_id, decided_at DESC
SQL,
            ['ids' => $missing],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        foreach ($rows as $row) {
            if (is_string($row['puzzle_id']) && is_string($row['puzzle_name'])) {
                $names[$row['puzzle_id']] = $row['puzzle_name'];
            }
        }

        return $names;
    }

    /**
     * @return list<PuzzleHistoryEntry>
     */
    private function decisions(string $puzzleId): array
    {
        // Decisions about the puzzle, plus the merge that folded it into another puzzle (that one names the survivor)
        $query = <<<SQL
SELECT
    d.action,
    d.decided_at,
    d.decided_by_id,
    d.decided_by_name,
    d.decided_by_code,
    d.source,
    d.puzzle_id,
    d.puzzle_name,
    d.change_request_id,
    d.merge_request_id,
    d.note,
    d.details,
    cr.reporter_id AS change_reporter_id,
    change_reporter.name AS change_reporter_name,
    change_reporter.code AS change_reporter_code,
    cr.proposed_name,
    cr.proposed_manufacturer_id,
    proposed_manufacturer.name AS proposed_manufacturer_name,
    cr.proposed_pieces_count,
    cr.proposed_ean,
    cr.proposed_identification_number,
    cr.proposed_image,
    cr.proposed_alternative_names,
    cr.proposed_name_language,
    cr.original_name,
    cr.original_manufacturer_id,
    original_manufacturer.name AS original_manufacturer_name,
    cr.original_pieces_count,
    cr.original_ean,
    cr.original_identification_number,
    cr.original_alternative_names,
    cr.original_name_language,
    mr.reporter_id AS merge_reporter_id,
    merge_reporter.name AS merge_reporter_name,
    merge_reporter.code AS merge_reporter_code,
    audit.snapshot_before AS merge_snapshot_before,
    audit.snapshot_after AS merge_snapshot_after
FROM puzzle_moderation_decision d
LEFT JOIN puzzle_change_request cr ON cr.id = d.change_request_id
LEFT JOIN player change_reporter ON change_reporter.id = cr.reporter_id
LEFT JOIN manufacturer proposed_manufacturer ON proposed_manufacturer.id = cr.proposed_manufacturer_id
LEFT JOIN manufacturer original_manufacturer ON original_manufacturer.id = cr.original_manufacturer_id
LEFT JOIN puzzle_merge_request mr ON mr.id = d.merge_request_id
LEFT JOIN player merge_reporter ON merge_reporter.id = mr.reporter_id
LEFT JOIN LATERAL (
    SELECT a.snapshot_before, a.snapshot_after
    FROM puzzle_merge_audit a
    WHERE a.merge_request_id = d.merge_request_id AND d.action = 'merge_request_approved'
    ORDER BY a.performed_at DESC
    LIMIT 1
) audit ON TRUE
WHERE d.puzzle_id = :puzzleId
   OR (d.action = 'merge_request_approved' AND (d.details::jsonb -> 'mergedPuzzleIds') @> :puzzleIdJson::jsonb)
-- Whole seconds only: decisions of one second keep their order by the time-ordered id
ORDER BY d.decided_at DESC, d.id DESC
SQL;

        $rows = $this->database->executeQuery($query, [
            'puzzleId' => $puzzleId,
            'puzzleIdJson' => json_encode([$puzzleId]),
        ])->fetchAllAssociative();

        // The other puzzles of a merge, named as they are now or as the log last saw them - merges recorded
        // before their audit existed hold bare ids
        $otherPuzzleIds = [];

        foreach ($rows as $row) {
            $details = self::json($row['details']);
            $otherPuzzleIds = [
                ...$otherPuzzleIds,
                ...self::stringList($details['mergedPuzzleIds'] ?? null),
                ...self::stringList($details['reportedDuplicatePuzzleIds'] ?? null),
            ];
        }

        $names = $this->knownNames($otherPuzzleIds);
        $entries = [];

        foreach ($rows as $row) {
            $entry = self::decisionEntry($row, $puzzleId, $names);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $names
     */
    private static function decisionEntry(array $row, string $puzzleId, array $names): null|PuzzleHistoryEntry
    {
        $details = self::json($row['details']);
        $at = new DateTimeImmutable(self::string($row['decided_at']) ?? 'now');
        $byId = self::string($row['decided_by_id']);
        $byName = self::string($row['decided_by_name']);
        $byCode = self::string($row['decided_by_code']);
        $viaInternalApi = $row['source'] === 'internal_api';
        $note = self::string($row['note']);
        $changeRequestId = self::string($row['change_request_id']);
        $mergeRequestId = self::string($row['merge_request_id']);

        $changes = is_array($details['before'] ?? null) && is_array($details['after'] ?? null)
            ? PuzzleHistoryChange::between($details['before'], $details['after'])
            : null;

        switch ($row['action']) {
            case 'puzzle_edited':
                return new PuzzleHistoryEntry(
                    kind: PuzzleHistoryEntryKind::Edited,
                    at: $at,
                    byId: $byId,
                    byName: $byName,
                    byCode: $byCode,
                    viaInternalApi: $viaInternalApi,
                    note: $note,
                    changes: $changes ?? [],
                );

            case 'change_request_approved':
            case 'change_request_rejected':
                $approved = $row['action'] === 'change_request_approved';
                $selectedFields = $details['selectedFields'] ?? null;

                return new PuzzleHistoryEntry(
                    kind: $approved ? PuzzleHistoryEntryKind::ChangeRequestApproved : PuzzleHistoryEntryKind::ChangeRequestRejected,
                    at: $at,
                    byId: $byId,
                    byName: $byName,
                    byCode: $byCode,
                    viaInternalApi: $viaInternalApi,
                    note: $note,
                    changes: $changes ?? [],
                    // What was applied is recorded since 2026-10-04 - before that, only the proposal is known
                    proposal: $approved && $changes !== null ? [] : self::proposal($row),
                    appliedFields: $approved && $changes === null && is_array($selectedFields)
                        ? array_values(array_map(
                            static fn (mixed $field): string => PuzzleHistoryChange::label(is_string($field) ? $field : ''),
                            $selectedFields,
                        ))
                        : null,
                    changeRequestId: $changeRequestId,
                    proposedById: self::string($row['change_reporter_id']),
                    proposedByName: self::string($row['change_reporter_name']),
                    proposedByCode: self::string($row['change_reporter_code']),
                );

            case 'merge_request_approved':
                $mergedAway = self::string($row['puzzle_id']) !== $puzzleId;

                return new PuzzleHistoryEntry(
                    kind: $mergedAway ? PuzzleHistoryEntryKind::MergedAway : PuzzleHistoryEntryKind::MergeApproved,
                    at: $at,
                    byId: $byId,
                    byName: $byName,
                    byCode: $byCode,
                    viaInternalApi: $viaInternalApi,
                    note: $note,
                    changes: $mergedAway ? [] : self::mergeChanges($row),
                    mergeRequestId: $mergeRequestId,
                    proposedById: self::string($row['merge_reporter_id']),
                    proposedByName: self::string($row['merge_reporter_name']),
                    proposedByCode: self::string($row['merge_reporter_code']),
                    puzzles: $mergedAway
                        ? [new PuzzleHistoryPuzzle(self::string($row['puzzle_id']) ?? '', self::string($row['puzzle_name']))]
                        : self::mergedPuzzles($row, $details, $names),
                    movedRecords: $mergedAway ? [] : self::movedRecords($row),
                );

            case 'merge_request_rejected':
                $reported = array_values(array_filter(
                    self::stringList($details['reportedDuplicatePuzzleIds'] ?? null),
                    static fn (string $id): bool => $id !== $puzzleId,
                ));

                return new PuzzleHistoryEntry(
                    kind: PuzzleHistoryEntryKind::MergeRejected,
                    at: $at,
                    byId: $byId,
                    byName: $byName,
                    byCode: $byCode,
                    viaInternalApi: $viaInternalApi,
                    note: $note,
                    mergeRequestId: $mergeRequestId,
                    proposedById: self::string($row['merge_reporter_id']),
                    proposedByName: self::string($row['merge_reporter_name']),
                    proposedByCode: self::string($row['merge_reporter_code']),
                    puzzles: array_map(static fn (string $id): PuzzleHistoryPuzzle => new PuzzleHistoryPuzzle($id, $names[$id] ?? null), $reported),
                );

            case 'puzzle_approved':
                return new PuzzleHistoryEntry(
                    kind: PuzzleHistoryEntryKind::Approved,
                    at: $at,
                    byId: $byId,
                    byName: $byName,
                    byCode: $byCode,
                    viaInternalApi: $viaInternalApi,
                    note: $note,
                    changes: $changes ?? [],
                    brandChoice: self::string($details['brandChoice'] ?? null),
                );

            case 'brand_approved':
                return new PuzzleHistoryEntry(
                    kind: PuzzleHistoryEntryKind::BrandApproved,
                    at: $at,
                    byId: $byId,
                    byName: $byName,
                    byCode: $byCode,
                    viaInternalApi: $viaInternalApi,
                    note: $note,
                    brandName: self::string($details['manufacturerName'] ?? null),
                );

            case 'brand_merged':
                $movedPuzzles = $details['movedPuzzles'] ?? null;

                return new PuzzleHistoryEntry(
                    kind: PuzzleHistoryEntryKind::BrandMerged,
                    at: $at,
                    byId: $byId,
                    byName: $byName,
                    byCode: $byCode,
                    viaInternalApi: $viaInternalApi,
                    note: $note,
                    movedRecords: is_int($movedPuzzles) && $movedPuzzles > 0 ? ['puzzles' => $movedPuzzles] : [],
                    brandName: self::string($details['mergedManufacturerName'] ?? null),
                    intoBrandName: self::string($details['intoManufacturerName'] ?? null),
                );
        }

        return null;
    }

    /**
     * Change requests decided without a line in the decision log: an EAN scanned for a puzzle without one is
     * written at once and its change request approved by the scanning player (LinkEanToPuzzleHandler).
     *
     * @return list<PuzzleHistoryEntry>
     */
    private function changeRequestsWithoutDecision(string $puzzleId): array
    {
        $query = <<<SQL
SELECT
    cr.status,
    cr.reviewed_at,
    cr.reviewed_by_id,
    reviewer.name AS reviewer_name,
    reviewer.code AS reviewer_code,
    cr.rejection_reason,
    cr.id AS change_request_id,
    cr.reporter_id AS change_reporter_id,
    change_reporter.name AS change_reporter_name,
    change_reporter.code AS change_reporter_code,
    cr.proposed_name,
    cr.proposed_manufacturer_id,
    proposed_manufacturer.name AS proposed_manufacturer_name,
    cr.proposed_pieces_count,
    cr.proposed_ean,
    cr.proposed_identification_number,
    cr.proposed_image,
    cr.proposed_alternative_names,
    cr.proposed_name_language,
    cr.original_name,
    cr.original_manufacturer_id,
    original_manufacturer.name AS original_manufacturer_name,
    cr.original_pieces_count,
    cr.original_ean,
    cr.original_identification_number,
    cr.original_alternative_names,
    cr.original_name_language
FROM puzzle_change_request cr
LEFT JOIN player reviewer ON reviewer.id = cr.reviewed_by_id
LEFT JOIN player change_reporter ON change_reporter.id = cr.reporter_id
LEFT JOIN manufacturer proposed_manufacturer ON proposed_manufacturer.id = cr.proposed_manufacturer_id
LEFT JOIN manufacturer original_manufacturer ON original_manufacturer.id = cr.original_manufacturer_id
WHERE cr.puzzle_id = :puzzleId
    AND cr.status IN ('approved', 'rejected')
    AND cr.reviewed_at IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM puzzle_moderation_decision d WHERE d.change_request_id = cr.id)
SQL;

        $rows = $this->database->executeQuery($query, ['puzzleId' => $puzzleId])->fetchAllAssociative();

        $entries = [];

        foreach ($rows as $row) {
            $approved = $row['status'] === 'approved';
            $proposal = self::proposal($row);
            $scanned = $approved
                && $row['reviewed_by_id'] === $row['change_reporter_id']
                && count($proposal) === 1
                && $proposal[0]->label === PuzzleHistoryChange::label('ean');

            $entries[] = new PuzzleHistoryEntry(
                kind: match (true) {
                    $scanned => PuzzleHistoryEntryKind::EanLinked,
                    $approved => PuzzleHistoryEntryKind::ChangeRequestApproved,
                    default => PuzzleHistoryEntryKind::ChangeRequestRejected,
                },
                at: new DateTimeImmutable(self::string($row['reviewed_at']) ?? 'now'),
                byId: self::string($row['reviewed_by_id']),
                byName: self::string($row['reviewer_name']),
                byCode: self::string($row['reviewer_code']),
                viaInternalApi: false,
                note: self::string($row['rejection_reason']),
                // Written as proposed - the proposal is what changed
                changes: $scanned ? $proposal : [],
                proposal: $scanned ? [] : $proposal,
                changeRequestId: self::string($row['change_request_id']),
                proposedById: self::string($row['change_reporter_id']),
                proposedByName: self::string($row['change_reporter_name']),
                proposedByCode: self::string($row['change_reporter_code']),
            );
        }

        return $entries;
    }

    /**
     * What a change request proposed: as the puzzle was when proposed, as proposed.
     *
     * @param array<string, mixed> $row
     *
     * @return list<PuzzleHistoryChange>
     */
    private static function proposal(array $row): array
    {
        $proposal = [];

        $fields = [
            'name' => ['original_name', 'proposed_name'],
            'piecesCount' => ['original_pieces_count', 'proposed_pieces_count'],
            'ean' => ['original_ean', 'proposed_ean'],
            'identificationNumber' => ['original_identification_number', 'proposed_identification_number'],
        ];

        foreach ($fields as $field => [$originalColumn, $proposedColumn]) {
            $proposed = self::text($row[$proposedColumn]);
            $original = self::text($row[$originalColumn]);

            // A code list proposed empty ('') removes every code (PuzzleChangeRequest::$proposedEan)
            $removed = $row[$proposedColumn] === '' && $original !== null;

            if (($proposed !== null && $proposed !== $original) || $removed) {
                $proposal[] = new PuzzleHistoryChange(PuzzleHistoryChange::label($field), $original, $proposed);
            }

            // The other names and the main title's language, then the brand right after the name, as everywhere else
            if ($field === 'name') {
                // Proposed together, only when proposedAlternativeNames is set (PuzzleChangeRequest)
                if (is_string($row['proposed_alternative_names'] ?? null)) {
                    $originalNames = self::json($row['original_alternative_names'] ?? null);

                    array_push($proposal, ...PuzzleHistoryChange::between(
                        ['nameLanguage' => $row['original_name_language'] ?? null, 'alternativeNames' => $originalNames],
                        ['nameLanguage' => $row['proposed_name_language'] ?? null, 'alternativeNames' => self::json($row['proposed_alternative_names'])],
                    ));
                }

                $proposedBrand = self::string($row['proposed_manufacturer_id']);

                if ($proposedBrand !== null && $proposedBrand !== self::string($row['original_manufacturer_id'])) {
                    $proposal[] = new PuzzleHistoryChange(
                        PuzzleHistoryChange::label('manufacturer'),
                        self::string($row['original_manufacturer_name']),
                        self::string($row['proposed_manufacturer_name']),
                    );
                }
            }
        }

        // The proposed file is moved away when used - shown as words, not as a picture that may be gone
        if (self::string($row['proposed_image']) !== null) {
            $proposal[] = new PuzzleHistoryChange(PuzzleHistoryChange::label('image'), null, 'A new image');
        }

        return $proposal;
    }

    /**
     * The survivor before and after a merge (puzzle_merge_audit, kept since 2026-09-16).
     *
     * @param array<string, mixed> $row
     *
     * @return list<PuzzleHistoryChange>
     */
    private static function mergeChanges(array $row): array
    {
        $before = self::json($row['merge_snapshot_before'])['survivorPuzzle'] ?? null;
        $after = self::json($row['merge_snapshot_after'])['survivorPuzzle'] ?? null;

        if (is_array($before) === false || is_array($after) === false) {
            return [];
        }

        return PuzzleHistoryChange::between($before, $after);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<mixed> $details
     * @param array<string, string> $names
     *
     * @return list<PuzzleHistoryPuzzle>
     */
    private static function mergedPuzzles(array $row, array $details, array $names): array
    {
        $snapshots = self::json($row['merge_snapshot_before'])['mergedPuzzles'] ?? null;

        if (is_array($snapshots) && $snapshots !== []) {
            $puzzles = [];

            foreach ($snapshots as $snapshot) {
                if (is_array($snapshot) === false || is_string($snapshot['id'] ?? null) === false) {
                    continue;
                }

                $description = array_filter([
                    self::text($snapshot['manufacturerName'] ?? null),
                    is_int($snapshot['piecesCount'] ?? null) ? $snapshot['piecesCount'] . ' pieces' : null,
                    self::text($snapshot['ean'] ?? null) !== null ? 'EAN ' . self::text($snapshot['ean']) : null,
                ]);

                $puzzles[] = new PuzzleHistoryPuzzle(
                    $snapshot['id'],
                    self::text($snapshot['name'] ?? null) ?? $names[$snapshot['id']] ?? null,
                    $description !== [] ? implode(' · ', $description) : null,
                );
            }

            return $puzzles;
        }

        $ids = self::stringList($details['mergedPuzzleIds'] ?? null);
        $recordedNames = self::stringList($details['mergedPuzzleNames'] ?? null);

        return array_map(
            static fn (string $id, int $index): PuzzleHistoryPuzzle => new PuzzleHistoryPuzzle(
                $id,
                $recordedNames[$index] ?? $names[$id] ?? null,
            ),
            $ids,
            array_keys($ids),
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, int>
     */
    private static function movedRecords(array $row): array
    {
        $migrated = self::json($row['merge_snapshot_before'])['migrated'] ?? null;

        if (is_array($migrated) === false) {
            return [];
        }

        $moved = [];

        foreach (self::MOVED_RECORD_LABELS as $key => $label) {
            $records = $migrated[$key] ?? null;

            if (is_array($records) === false) {
                continue;
            }

            // Either a list of ids, or ['moved' => ids, 'droppedAsDuplicate' => ids]
            $count = array_key_exists('moved', $records) && is_array($records['moved'])
                ? count($records['moved'])
                : count($records);

            if ($count > 0) {
                $moved[$label] = $count;
            }
        }

        return $moved;
    }

    /**
     * @return array<mixed>
     */
    private static function json(mixed $value): array
    {
        if (is_string($value) === false) {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_array($value) === false) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    private static function string(mixed $value): null|string
    {
        return is_string($value) ? $value : null;
    }

    private static function text(mixed $value): null|string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
