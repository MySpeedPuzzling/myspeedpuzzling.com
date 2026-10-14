<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\OnlineFinishPhotoCheck;
use SpeedPuzzling\Web\Value\OnlineFinishCheckVerdict;
use SpeedPuzzling\Web\Value\OnlineMediaKind;

/**
 * The finish photos of an online round (`candidate`, `button`, `end`) with their completeness check
 * (docs/features/online-competitions/README.md §5.8, Phase 3) - one statement for the whole round (the review's list,
 * the accuracy report) or one entry (the review's detail): each photo's latest verdict over every worker version, a
 * failed check only when no version gave one, `pending` while none came yet - none at all for a photo that never
 * arrived (abandoned: the review says so instead).
 *
 * @phpstan-type OnlineFinishPhotoRow array{
 *     media_id: string,
 *     online_entry_id: string,
 *     kind: string,
 *     related_event_id: null|string,
 *     taken_ms: int,
 *     uploaded: bool,
 *     check: null|OnlineFinishPhotoCheck,
 * }
 */
readonly final class GetOnlineFinishPhotoChecks
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<string, list<OnlineFinishPhotoRow>> by online entry id, each by the moment the photo was taken
     */
    public function forRound(string $roundId): array
    {
        if (Uuid::isValid($roundId) === false) {
            return [];
        }

        $byEntry = [];

        foreach ($this->rows('cpr.round_id = :id', strtolower($roundId)) as $row) {
            $byEntry[$row['online_entry_id']][] = $row;
        }

        return $byEntry;
    }

    /**
     * @return array<string, OnlineFinishPhotoCheck> by media id - the entry's finish photos that have one
     */
    public function forEntry(string $onlineEntryId): array
    {
        if (Uuid::isValid($onlineEntryId) === false) {
            return [];
        }

        $checks = [];

        foreach ($this->rows('m.online_entry_id = :id', strtolower($onlineEntryId)) as $row) {
            if ($row['check'] !== null) {
                $checks[$row['media_id']] = $row['check'];
            }
        }

        return $checks;
    }

    /**
     * The photo the review stands on for a finish moment (a tap's `button` photo, a candidate's `candidate` photo): the
     * latest one that arrived - a retry after a store failure is a new row -, else the latest one (the review page's
     * own rule, online_review_controller.js tapMedia())
     *
     * @param list<OnlineFinishPhotoRow> $photos
     * @return null|OnlineFinishPhotoRow
     */
    public static function photoOf(array $photos, OnlineMediaKind $kind, string $eventId): null|array
    {
        $matching = array_values(array_filter(
            $photos,
            static fn (array $photo): bool => $photo['kind'] === $kind->value && $photo['related_event_id'] !== null && strtolower($photo['related_event_id']) === strtolower($eventId),
        ));
        $arrived = array_values(array_filter($matching, static fn (array $photo): bool => $photo['uploaded']));

        return $arrived[count($arrived) - 1] ?? $matching[count($matching) - 1] ?? null;
    }

    /**
     * @return list<OnlineFinishPhotoRow>
     */
    private function rows(string $condition, string $id): array
    {
        $kinds = array_map(
            static fn (OnlineMediaKind $kind): string => $kind->value,
            array_values(array_filter(OnlineMediaKind::cases(), static fn (OnlineMediaKind $kind): bool => $kind->isFinishPhoto())),
        );

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    m.id AS media_id,
    m.online_entry_id,
    m.kind,
    m.related_event_id,
    m.taken_ms,
    m.uploaded_at_ms IS NOT NULL AS uploaded,
    m.abandoned_at IS NOT NULL AS abandoned,
    c.verdict,
    c.confidence,
    c.gaps,
    c.reason,
    c.error,
    c.method,
    c.worker_version,
    c.image_width,
    c.image_height,
    (EXTRACT(EPOCH FROM c.checked_at) * 1000)::bigint AS checked_at_ms
FROM online_entry_media m
INNER JOIN online_entry oe ON oe.id = m.online_entry_id
INNER JOIN competition_participant_round cpr ON cpr.id = oe.entry_id
LEFT JOIN LATERAL (
    SELECT mc.verdict, mc.confidence, mc.gaps, mc.reason, mc.error, mc.method, mc.worker_version, mc.image_width, mc.image_height, mc.checked_at
    FROM online_media_check mc
    WHERE mc.media_id = m.id
    ORDER BY (mc.verdict = :failed), mc.checked_at DESC, mc.id DESC
    LIMIT 1
) c ON TRUE
WHERE {$condition}
    AND m.kind IN (:kinds)
ORDER BY m.online_entry_id, m.taken_ms, m.id
SQL,
            ['id' => $id, 'kinds' => $kinds, 'failed' => OnlineFinishCheckVerdict::Failed->value],
            ['kinds' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): array => [
            'media_id' => is_string($row['media_id']) ? $row['media_id'] : '',
            'online_entry_id' => is_string($row['online_entry_id']) ? $row['online_entry_id'] : '',
            'kind' => is_string($row['kind']) ? $row['kind'] : '',
            'related_event_id' => is_string($row['related_event_id']) ? $row['related_event_id'] : null,
            'taken_ms' => is_numeric($row['taken_ms']) ? (int) $row['taken_ms'] : 0,
            'uploaded' => $row['uploaded'] === true,
            'check' => self::check($row),
        ], $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function check(array $row): null|OnlineFinishPhotoCheck
    {
        $verdict = is_string($row['verdict']) ? OnlineFinishCheckVerdict::tryFrom($row['verdict']) : null;

        if ($verdict === null) {
            // Never arrived and never will - nothing to check
            return $row['abandoned'] === true ? null : OnlineFinishPhotoCheck::pending();
        }

        $gaps = is_string($row['gaps']) ? json_decode($row['gaps'], true) : null;
        $boxes = [];

        // jsonb keeps its own key order - the boxes go out as {x, y, w, h}
        foreach (is_array($gaps) ? $gaps : [] as $gap) {
            if (is_array($gap) && is_int($gap['x'] ?? null) && is_int($gap['y'] ?? null) && is_int($gap['w'] ?? null) && is_int($gap['h'] ?? null)) {
                $boxes[] = ['x' => $gap['x'], 'y' => $gap['y'], 'w' => $gap['w'], 'h' => $gap['h']];
            }
        }

        return new OnlineFinishPhotoCheck(
            state: $verdict->value,
            confidence: is_numeric($row['confidence']) ? round((float) $row['confidence'], 3) : null,
            gaps: $boxes,
            reason: $verdict === OnlineFinishCheckVerdict::Failed
                ? (is_string($row['error']) ? $row['error'] : null)
                : (is_string($row['reason']) ? $row['reason'] : null),
            method: is_string($row['method']) ? $row['method'] : null,
            version: is_string($row['worker_version']) ? $row['worker_version'] : null,
            imageWidth: is_numeric($row['image_width']) ? (int) $row['image_width'] : null,
            imageHeight: is_numeric($row['image_height']) ? (int) $row['image_height'] : null,
            checkedAtMs: is_numeric($row['checked_at_ms']) ? (int) $row['checked_at_ms'] : null,
        );
    }
}
