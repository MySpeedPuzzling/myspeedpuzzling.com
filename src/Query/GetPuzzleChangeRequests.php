<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\PuzzleChangeRequestOverview;
use SpeedPuzzling\Web\Value\PuzzleChangeRequestFilter;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use SpeedPuzzling\Web\Value\PuzzleSecrecy;

readonly final class GetPuzzleChangeRequests
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{pending: int, approved: int, rejected: int, outdated: int}
     */
    public function countByStatus(bool $includeSecret = false, null|PuzzleChangeRequestFilter $filter = null): array
    {
        // A secret competition puzzle is in the moderators' counts only after its reveal; admins, who may correct it,
        // count it (PuzzleSecrecy)
        $notSecret = $includeSecret ? 'true' : PuzzleSecrecy::sqlNotSecret('p');
        $parameters = ['now' => $this->clock->now()->format('Y-m-d H:i:s')];
        $filterCondition = self::filterCondition($filter, $parameters);
        $query = <<<SQL
SELECT
    COUNT(*) FILTER (WHERE pcr.status = 'pending') as pending,
    COUNT(*) FILTER (WHERE pcr.status = 'approved') as approved,
    COUNT(*) FILTER (WHERE pcr.status = 'rejected') as rejected,
    COUNT(*) FILTER (WHERE pcr.status = 'outdated') as outdated
FROM puzzle_change_request pcr
JOIN puzzle p ON p.id = pcr.puzzle_id
WHERE {$notSecret}
    {$filterCondition}
SQL;

        $row = $this->database->fetchAssociative($query, $parameters);

        if ($row === false) {
            return ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'outdated' => 0];
        }

        /** @var int $pending */
        $pending = $row['pending'];
        /** @var int $approved */
        $approved = $row['approved'];
        /** @var int $rejected */
        $rejected = $row['rejected'];
        /** @var int $outdated */
        $outdated = $row['outdated'];

        return [
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'outdated' => $outdated,
        ];
    }

    /**
     * @return list<PuzzleChangeRequestOverview>
     */
    public function allPending(bool $includeSecret = false, null|PuzzleChangeRequestFilter $filter = null): array
    {
        return $this->byStatus(PuzzleReportStatus::Pending, 'pcr.submitted_at DESC', $includeSecret, $filter);
    }

    /**
     * @return list<PuzzleChangeRequestOverview>
     */
    public function allApproved(bool $includeSecret = false, null|PuzzleChangeRequestFilter $filter = null): array
    {
        return $this->byStatus(PuzzleReportStatus::Approved, 'pcr.reviewed_at DESC', $includeSecret, $filter);
    }

    /**
     * @return list<PuzzleChangeRequestOverview>
     */
    public function allRejected(bool $includeSecret = false, null|PuzzleChangeRequestFilter $filter = null): array
    {
        return $this->byStatus(PuzzleReportStatus::Rejected, 'pcr.reviewed_at DESC', $includeSecret, $filter);
    }

    /**
     * Closed without a review - the puzzle had every proposed value already (PuzzleReportStatus::Outdated).
     *
     * @return list<PuzzleChangeRequestOverview>
     */
    public function allOutdated(bool $includeSecret = false, null|PuzzleChangeRequestFilter $filter = null): array
    {
        return $this->byStatus(PuzzleReportStatus::Outdated, 'pcr.reviewed_at DESC', $includeSecret, $filter);
    }

    /**
     * Pending proposals of a new picture for the picture review (docs/features/puzzle-approvals.md "Picture
     * proposals"), the pipeline's surest first: highest confidence first, proposals without one last, then by filing.
     *
     * @return list<PuzzleChangeRequestOverview>
     */
    public function pendingPictureProposals(
        PuzzleChangeRequestFilter $filter,
        int $limit,
        int $offset,
        bool $includeSecret = false,
    ): array {
        return $this->byStatus(
            PuzzleReportStatus::Pending,
            'pcr.confidence DESC NULLS LAST, pcr.submitted_at, pcr.id',
            $includeSecret,
            $filter,
            onlyWithProposedImage: true,
            limit: $limit,
            offset: $offset,
        );
    }

    public function countPendingPictureProposals(PuzzleChangeRequestFilter $filter, bool $includeSecret = false): int
    {
        $notSecret = $includeSecret ? 'true' : PuzzleSecrecy::sqlNotSecret('p');
        $parameters = ['now' => $this->clock->now()->format('Y-m-d H:i:s')];
        $filterCondition = self::filterCondition($filter, $parameters);

        $count = $this->database->fetchOne(
            <<<SQL
SELECT COUNT(*)
FROM puzzle_change_request pcr
JOIN puzzle p ON p.id = pcr.puzzle_id
WHERE pcr.status = 'pending'
    AND pcr.proposed_image IS NOT NULL
    AND {$notSecret}
    {$filterCondition}
SQL,
            $parameters,
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Every tag a change request carries (e.g. "artwork_crop") - the queue's tag filter offers them.
     *
     * @return list<string>
     */
    public function knownTags(): array
    {
        $tags = $this->database->fetchFirstColumn(<<<SQL
SELECT DISTINCT tag
FROM puzzle_change_request pcr, jsonb_array_elements_text(pcr.tags) AS tag
WHERE pcr.tags IS NOT NULL
ORDER BY tag
SQL);

        return array_values(array_filter($tags, is_string(...)));
    }

    /**
     * The queue's filters as SQL conditions (starting with AND), their values added to $parameters.
     *
     * @param array<string, mixed> $parameters
     */
    private static function filterCondition(null|PuzzleChangeRequestFilter $filter, array &$parameters): string
    {
        if ($filter === null) {
            return '';
        }

        $conditions = [];

        if ($filter->source !== null) {
            // A row of the release before the column existed was filed by a player (PuzzleChangeRequestSource)
            $conditions[] = "COALESCE(pcr.source, 'player') = :filterSource";
            $parameters['filterSource'] = $filter->source->value;
        }

        if ($filter->tag !== null) {
            $conditions[] = 'pcr.tags @> CAST(:filterTag AS jsonb)';
            $parameters['filterTag'] = json_encode([$filter->tag], JSON_THROW_ON_ERROR);
        }

        return $conditions !== [] ? 'AND ' . implode(' AND ', $conditions) : '';
    }

    /**
     * The puzzle a change request is about - what its approval locks (ApprovePuzzleChangeRequest). Null when there is
     * no such request.
     */
    public function puzzleIdOf(string $id): null|string
    {
        $puzzleId = $this->database->fetchOne(
            'SELECT puzzle_id FROM puzzle_change_request WHERE id = :id',
            ['id' => $id],
        );

        return is_string($puzzleId) ? $puzzleId : null;
    }

    /**
     * The approval of a change request as the decision log recorded it - its details (PuzzleChangeRequestOutcome) and
     * the reviewer's note. Null when it has no line there. Only for a request read through byId(), which already keeps
     * a secret competition puzzle from whoever may not see it.
     *
     * @return null|array{details: null|array<mixed>, note: null|string}
     */
    public function approvalDecision(PuzzleChangeRequestOverview $changeRequest): null|array
    {
        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT details, note
FROM puzzle_moderation_decision
-- By the puzzle too: its column is indexed, the change request's is not
WHERE puzzle_id = :puzzleId AND change_request_id = :id AND action = 'change_request_approved'
ORDER BY decided_at DESC
LIMIT 1
SQL,
            ['puzzleId' => $changeRequest->puzzleId, 'id' => $changeRequest->id],
        );

        if ($row === false) {
            return null;
        }

        $details = is_string($row['details']) ? json_decode($row['details'], true) : null;

        return [
            'details' => is_array($details) ? $details : null,
            'note' => is_string($row['note']) && trim($row['note']) !== '' ? $row['note'] : null,
        ];
    }

    public function byId(string $id, bool $includeSecret = false): null|PuzzleChangeRequestOverview
    {
        // Admins correct a secret competition puzzle before its reveal; moderators never see it (PuzzleSecrecy)
        $notSecret = $includeSecret ? 'true' : PuzzleSecrecy::sqlNotSecret('p');
        $query = <<<SQL
SELECT
    pcr.id,
    pcr.status,
    pcr.submitted_at,
    pcr.reviewed_at,
    pcr.rejection_reason,
    pcr.outdated_reason,
    pcr.merged_from_puzzle_id,
    pcr.proposed_name,
    pcr.proposed_pieces_count,
    pcr.proposed_ean,
    pcr.proposed_identification_number,
    pcr.proposed_image,
    pcr.proposed_alternative_names,
    pcr.proposed_name_language,
    pcr.created_manufacturer_name,
    pcr.original_name,
    pcr.original_pieces_count,
    pcr.original_ean,
    pcr.original_identification_number,
    pcr.original_image,
    pcr.original_alternative_names,
    pcr.original_name_language,
    pcr.source,
    pcr.tags,
    pcr.confidence,
    pcr.model_version,
    pcr.source_image_fingerprint,
    p.id as puzzle_id,
    p.name as puzzle_name,
    p.name_language as puzzle_name_language,
    p.pieces_count as puzzle_pieces_count,
    p.alternative_names as puzzle_alternative_names,
    p.manufacturer_id as puzzle_manufacturer_id,
    p.ean as puzzle_ean,
    p.identification_number as puzzle_identification_number,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image END AS puzzle_image,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image_ratio END AS puzzle_image_ratio,
    p.image AS puzzle_record_image,
    pm.name as puzzle_manufacturer_name,
    reporter.id as reporter_id,
    reporter.name as reporter_name,
    reporter.code as reporter_code,
    reviewer.id as reviewer_id,
    reviewer.name as reviewer_name,
    proposed_m.id as proposed_manufacturer_id,
    proposed_m.name as proposed_manufacturer_name,
    original_m.id as original_manufacturer_id,
    original_m.name as original_manufacturer_name,
    p.added_at as puzzle_added_at,
    added_by.id as puzzle_added_by_id,
    added_by.name as puzzle_added_by_name,
    added_by.code as puzzle_added_by_code
FROM puzzle_change_request pcr
JOIN puzzle p ON p.id = pcr.puzzle_id
LEFT JOIN manufacturer pm ON pm.id = p.manufacturer_id
JOIN player reporter ON reporter.id = pcr.reporter_id
LEFT JOIN player reviewer ON reviewer.id = pcr.reviewed_by_id
LEFT JOIN manufacturer proposed_m ON proposed_m.id = pcr.proposed_manufacturer_id
LEFT JOIN manufacturer original_m ON original_m.id = pcr.original_manufacturer_id
LEFT JOIN player added_by ON added_by.id = p.added_by_user_id
WHERE pcr.id = :id
    AND {$notSecret}
SQL;

        $row = $this->database->fetchAssociative($query, [
            'id' => $id,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        if ($row === false) {
            return null;
        }

        return PuzzleChangeRequestOverview::fromDatabaseRow($row);
    }

    /**
     * @return list<PuzzleChangeRequestOverview>
     */
    private function byStatus(
        PuzzleReportStatus $status,
        string $orderBy,
        bool $includeSecret = false,
        null|PuzzleChangeRequestFilter $filter = null,
        bool $onlyWithProposedImage = false,
        null|int $limit = null,
        int $offset = 0,
    ): array {
        // Admins correct a secret competition puzzle before its reveal; moderators never see it (PuzzleSecrecy)
        $notSecret = $includeSecret ? 'true' : PuzzleSecrecy::sqlNotSecret('p');
        $parameters = [
            'status' => $status->value,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];
        $filterCondition = self::filterCondition($filter, $parameters);
        $imageCondition = $onlyWithProposedImage ? 'AND pcr.proposed_image IS NOT NULL' : '';
        $page = '';

        if ($limit !== null) {
            $page = 'LIMIT :limit OFFSET :offset';
            $parameters['limit'] = $limit;
            $parameters['offset'] = $offset;
        }

        $query = <<<SQL
SELECT
    pcr.id,
    pcr.status,
    pcr.submitted_at,
    pcr.reviewed_at,
    pcr.rejection_reason,
    pcr.outdated_reason,
    pcr.merged_from_puzzle_id,
    pcr.proposed_name,
    pcr.proposed_pieces_count,
    pcr.proposed_ean,
    pcr.proposed_identification_number,
    pcr.proposed_image,
    pcr.proposed_alternative_names,
    pcr.proposed_name_language,
    pcr.created_manufacturer_name,
    pcr.original_name,
    pcr.original_pieces_count,
    pcr.original_ean,
    pcr.original_identification_number,
    pcr.original_image,
    pcr.original_alternative_names,
    pcr.original_name_language,
    pcr.source,
    pcr.tags,
    pcr.confidence,
    pcr.model_version,
    pcr.source_image_fingerprint,
    p.id as puzzle_id,
    p.name as puzzle_name,
    p.name_language as puzzle_name_language,
    p.pieces_count as puzzle_pieces_count,
    p.alternative_names as puzzle_alternative_names,
    p.manufacturer_id as puzzle_manufacturer_id,
    p.ean as puzzle_ean,
    p.identification_number as puzzle_identification_number,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image END AS puzzle_image,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image_ratio END AS puzzle_image_ratio,
    p.image AS puzzle_record_image,
    pm.name as puzzle_manufacturer_name,
    reporter.id as reporter_id,
    reporter.name as reporter_name,
    reporter.code as reporter_code,
    reviewer.id as reviewer_id,
    reviewer.name as reviewer_name,
    proposed_m.id as proposed_manufacturer_id,
    proposed_m.name as proposed_manufacturer_name,
    original_m.id as original_manufacturer_id,
    original_m.name as original_manufacturer_name
FROM puzzle_change_request pcr
JOIN puzzle p ON p.id = pcr.puzzle_id
LEFT JOIN manufacturer pm ON pm.id = p.manufacturer_id
JOIN player reporter ON reporter.id = pcr.reporter_id
LEFT JOIN player reviewer ON reviewer.id = pcr.reviewed_by_id
LEFT JOIN manufacturer proposed_m ON proposed_m.id = pcr.proposed_manufacturer_id
LEFT JOIN manufacturer original_m ON original_m.id = pcr.original_manufacturer_id
WHERE pcr.status = :status
    AND {$notSecret}
    {$filterCondition}
    {$imageCondition}
ORDER BY {$orderBy}
{$page}
SQL;

        $rows = $this->database->fetchAllAssociative($query, $parameters);

        return array_map(
            static fn(array $row): PuzzleChangeRequestOverview => PuzzleChangeRequestOverview::fromDatabaseRow($row),
            $rows,
        );
    }
}
