<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\ChangeRequestForInternalApi;
use SpeedPuzzling\Web\Value\PuzzleChangeRequestSource;
use SpeedPuzzling\Web\Value\PuzzleImageChoice;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;

/**
 * Change requests for the internal API (GET /internal-api/puzzle-change-requests, docs/features/internal-api.md "Puzzle
 * change requests") - what the image pipeline's auto-resolve and its precision calibration read: in id order (= filing
 * order, UUIDv7), a page at a time (keyset: `after` = the last id of the previous page), each with its latest decision
 * from the decision log. A request of a puzzle whose picture is hidden now (hide_until / hide_image_until in the
 * future - a secret competition puzzle, a placeholder before its reveal) is never listed, like the image analysis API.
 *
 * One statement; the decision is one index lookup per row (puzzle_moderation_decision.change_request_id).
 */
readonly final class GetChangeRequestsForInternalApi
{
    public const int DEFAULT_LIMIT = 100;

    public const int MAX_LIMIT = 500;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param null|PuzzleReportStatus $status null = every status
     *
     * @return array{items: list<ChangeRequestForInternalApi>, nextCursor: null|string}
     */
    public function page(
        null|PuzzleReportStatus $status,
        null|PuzzleChangeRequestSource $source,
        null|string $tag,
        int $limit,
        null|string $after,
    ): array {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $parameters = [
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            'limit' => $limit,
            // A row of the release before the column existed was filed by a player (PuzzleChangeRequestSource)
            'defaultSource' => PuzzleChangeRequestSource::Player->value,
        ];
        $conditions = [];

        if ($status !== null) {
            $conditions[] = 'pcr.status = :status';
            $parameters['status'] = $status->value;
        }

        if ($source !== null) {
            $conditions[] = 'COALESCE(pcr.source, :defaultSource) = :source';
            $parameters['source'] = $source->value;
        }

        if ($tag !== null) {
            $conditions[] = 'pcr.tags @> CAST(:tag AS jsonb)';
            $parameters['tag'] = json_encode([$tag], JSON_THROW_ON_ERROR);
        }

        if ($after !== null) {
            $conditions[] = 'pcr.id > :after';
            $parameters['after'] = strtolower($after);
        }

        $where = $conditions !== [] ? 'AND ' . implode(' AND ', $conditions) : '';

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT
    pcr.id,
    pcr.puzzle_id,
    p.name AS puzzle_name,
    pcr.status,
    COALESCE(pcr.source, :defaultSource) AS source,
    pcr.tags,
    pcr.confidence,
    pcr.model_version,
    pcr.source_image_fingerprint,
    pcr.original_image,
    pcr.proposed_image,
    pcr.original_archive_path,
    pcr.submitted_at,
    pcr.reviewed_at,
    pcr.rejection_reason,
    d.action AS decision_action,
    d.decided_at,
    d.decided_by_id,
    d.decided_by_name,
    d.source AS decision_source,
    d.note AS decision_note,
    CAST(d.details AS jsonb) ->> 'decisionNote' AS decision_details_note,
    CAST(d.details AS jsonb) ->> 'image' AS decision_image,
    CAST(d.details AS jsonb) -> 'after' ->> 'image' AS decision_image_after
FROM puzzle_change_request pcr
JOIN puzzle p ON p.id = pcr.puzzle_id
LEFT JOIN LATERAL (
    SELECT d.*
    FROM puzzle_moderation_decision d
    WHERE d.change_request_id = pcr.id
      AND d.action IN ('change_request_approved', 'change_request_rejected', 'change_request_outdated')
    ORDER BY d.decided_at DESC
    LIMIT 1
) d ON true
WHERE (p.hide_until IS NULL OR p.hide_until <= :now)
    AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
    {$where}
ORDER BY pcr.id
LIMIT :limit
SQL,
            $parameters,
        )->fetchAllAssociative();

        $items = array_map(self::item(...), $rows);
        $lastItem = $items !== [] ? $items[array_key_last($items)] : null;

        return [
            'items' => $items,
            'nextCursor' => count($items) === $limit && $lastItem !== null ? $lastItem->id : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function item(array $row): ChangeRequestForInternalApi
    {
        /** @var array{id: string, puzzle_id: string, puzzle_name: string, status: string, source: string, tags: null|string, confidence: null|float|int|string, model_version: null|string, source_image_fingerprint: null|string, original_image: null|string, proposed_image: null|string, original_archive_path: null|string, submitted_at: string, reviewed_at: null|string, rejection_reason: null|string, decision_action: null|string, decided_at: null|string, decided_by_id: null|string, decided_by_name: null|string, decision_source: null|string, decision_note: null|string, decision_details_note: null|string, decision_image: null|string, decision_image_after: null|string} $row */
        $status = PuzzleReportStatus::from($row['status']);

        $tags = [];

        if ($row['tags'] !== null) {
            $decoded = json_decode($row['tags'], true, flags: JSON_THROW_ON_ERROR);

            foreach (is_array($decoded) ? $decoded : [] as $tag) {
                if (is_string($tag)) {
                    $tags[] = $tag;
                }
            }
        }

        // Approving with the proposed picture moves the proposal's file under the puzzle's new name; rejecting deletes it
        $proposedImage = match (true) {
            $status === PuzzleReportStatus::Approved
                && $row['decision_image'] === PuzzleImageChoice::Proposed->value
                && $row['decision_image_after'] !== null => $row['decision_image_after'],
            $status === PuzzleReportStatus::Rejected => null,
            default => $row['proposed_image'],
        };

        return new ChangeRequestForInternalApi(
            id: $row['id'],
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            status: $status,
            source: PuzzleChangeRequestSource::from($row['source']),
            tags: $tags,
            confidence: $row['confidence'] !== null ? (float) $row['confidence'] : null,
            modelVersion: $row['model_version'],
            sourceImageFingerprint: $row['source_image_fingerprint'],
            originalImage: $row['original_image'],
            proposedImage: $proposedImage,
            originalArchivePath: $row['original_archive_path'],
            submittedAt: new DateTimeImmutable($row['submitted_at']),
            decision: self::decision($status, $row),
        );
    }

    /**
     * @param array{reviewed_at: null|string, rejection_reason: null|string, decision_action: null|string, decided_at: null|string, decided_by_id: null|string, decided_by_name: null|string, decision_source: null|string, decision_note: null|string, decision_details_note: null|string, decision_image: null|string, ...} $row
     *
     * @return null|array{outcome: string, decidedAt: DateTimeImmutable, decidedById: null|string, decidedByName: null|string, source: null|string, note: null|string, rejectionReason: null|string, image: null|string}
     */
    private static function decision(PuzzleReportStatus $status, array $row): null|array
    {
        if ($status === PuzzleReportStatus::Pending) {
            return null;
        }

        $decidedAt = $row['decided_at'] ?? $row['reviewed_at'];

        if ($decidedAt === null) {
            return null;
        }

        $rejected = $status === PuzzleReportStatus::Rejected;
        $logged = $row['decision_action'] !== null;

        return [
            'outcome' => $status->value,
            'decidedAt' => new DateTimeImmutable($decidedAt),
            'decidedById' => $row['decided_by_id'],
            'decidedByName' => $row['decided_by_name'],
            'source' => $row['decision_source'],
            // A rejection's `note` is the reason the player read - its internal note is in the details
            'note' => match (true) {
                $logged === false => null,
                $rejected => $row['decision_details_note'],
                $status === PuzzleReportStatus::Approved => $row['decision_note'],
                default => null,
            },
            'rejectionReason' => $rejected ? ($row['decision_note'] ?? $row['rejection_reason']) : null,
            // What an approval did with the picture (PuzzleImageChoice: proposed / keep / upload) - an approval keeping
            // the old picture or taking another upload did not accept the proposed one
            'image' => $logged && $status === PuzzleReportStatus::Approved ? $row['decision_image'] : null,
        ];
    }
}
