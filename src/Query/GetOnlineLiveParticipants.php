<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\OnlineMediaKind;

/**
 * The control room's grid of an online round (docs/features/online-competitions/implementation-plan.md WP 1.7): every
 * participant entered in the round and going (CompetitionParticipantGoing), with their online entry's summary - ready,
 * the device holding it, the clock quality at ready, what they reported at time-up, the review status and flags -, the
 * last Finished tap not undone of the round's current run (a `finish.button.undone` names the tap it takes back:
 * `{eventId}`; a run is one start of the round clock - an earlier run's taps lie before its start), the latest finish
 * candidate of the run (Phase 2: raised hands the phone's detector held - with how many the run has) and the latest
 * uploaded periodic photo. One statement, whatever the number of participants; the photos' rows in one more
 * (latestPhotos()) - pinned by OnlineLiveQueryBudgetTest.
 *
 * @phpstan-type OnlineLiveRow array{
 *     participant_id: string,
 *     name: string,
 *     country: null|string,
 *     entry_id: string,
 *     online_entry_id: null|string,
 *     active_device_id: null|string,
 *     ready_at_ms: null|int,
 *     clock_rtt_ms: null|float,
 *     reported_measure: null|string,
 *     reported_pieces: null|int,
 *     review_status: null|string,
 *     flags: list<string>,
 *     tap_id: null|string,
 *     tap_ms: null|int,
 *     tap_received_ms: null|int,
 *     candidate_id: null|string,
 *     candidate_ms: null|int,
 *     candidate_received_ms: null|int,
 *     candidate_count: int,
 *     photo_id: null|string,
 *     photo_taken_ms: null|int,
 * }
 */
readonly final class GetOnlineLiveParticipants
{
    public function __construct(
        private Connection $database,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param null|int $runStartedAtMs the start of the round's current run (RoundClock::$startedAtMs) - null: no run, no tap
     * @return list<OnlineLiveRow>
     */
    public function forRound(string $roundId, null|int $runStartedAtMs): array
    {
        if (Uuid::isValid($roundId) === false) {
            return [];
        }

        $going = CompetitionParticipantGoing::sql('cp');
        $tapOfTheRun = $runStartedAtMs === null ? 'AND FALSE' : 'AND e.client_ms >= :runStartedAtMs';

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    cp.id AS participant_id,
    cp.name,
    cp.country,
    cpr.id AS entry_id,
    oe.id AS online_entry_id,
    oe.active_device_id,
    (EXTRACT(EPOCH FROM oe.ready_at) * 1000)::bigint AS ready_at_ms,
    oe.clock_rtt_ms,
    oe.reported_measure,
    oe.reported_pieces,
    oe.review_status,
    oe.flags,
    tap.id AS tap_id,
    tap.client_ms AS tap_ms,
    tap.received_at_ms AS tap_received_ms,
    candidate.id AS candidate_id,
    candidate.client_ms AS candidate_ms,
    candidate.received_at_ms AS candidate_received_ms,
    candidate.candidates AS candidate_count,
    photo.id AS photo_id,
    photo.taken_ms AS photo_taken_ms
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
LEFT JOIN online_entry oe ON oe.entry_id = cpr.id
LEFT JOIN LATERAL (
    SELECT e.id, e.client_ms, e.received_at_ms
    FROM online_entry_event e
    WHERE e.online_entry_id = oe.id
        AND e.type = :tap
        {$tapOfTheRun}
        AND NOT EXISTS (
            SELECT 1
            FROM online_entry_event undone
            WHERE undone.online_entry_id = e.online_entry_id
                AND undone.type = :undone
                AND lower(undone.payload->>'eventId') = e.id::text
        )
    ORDER BY e.client_ms DESC
    LIMIT 1
) tap ON TRUE
LEFT JOIN LATERAL (
    SELECT e.id, e.client_ms, e.received_at_ms, COUNT(*) OVER () AS candidates
    FROM online_entry_event e
    WHERE e.online_entry_id = oe.id
        AND e.type = :candidate
        {$tapOfTheRun}
    ORDER BY e.client_ms DESC
    LIMIT 1
) candidate ON TRUE
LEFT JOIN LATERAL (
    SELECT m.id, m.taken_ms
    FROM online_entry_media m
    WHERE m.online_entry_id = oe.id
        AND m.kind = :periodic
        AND m.uploaded_at_ms IS NOT NULL
    ORDER BY m.taken_ms DESC
    LIMIT 1
) photo ON TRUE
WHERE cpr.round_id = :roundId
    AND {$going}
ORDER BY lower(cp.name), cp.id
SQL,
            [
                'roundId' => strtolower($roundId),
                'tap' => OnlineEventType::FinishButton->value,
                'undone' => OnlineEventType::FinishButtonUndone->value,
                'candidate' => OnlineEventType::FinishCandidate->value,
                'periodic' => OnlineMediaKind::Periodic->value,
                ...($runStartedAtMs === null ? [] : ['runStartedAtMs' => $runStartedAtMs]),
            ],
            $runStartedAtMs === null ? [] : ['runStartedAtMs' => ParameterType::INTEGER],
        );

        return array_map(self::row(...), $rows);
    }

    /**
     * The latest photos' rows, for their short-lived URLs (OnlineMediaStorage::presignGet() takes the media) - one
     * statement for all of them.
     *
     * @param list<string> $mediaIds
     * @return array<string, OnlineEntryMedia> media id => media
     */
    public function latestPhotos(array $mediaIds): array
    {
        $mediaIds = array_values(array_filter($mediaIds, static fn (string $id): bool => Uuid::isValid($id)));

        if ($mediaIds === []) {
            return [];
        }

        /** @var list<OnlineEntryMedia> $media */
        $media = $this->entityManager->createQueryBuilder()
            ->select('media')
            ->from(OnlineEntryMedia::class, 'media')
            ->where('media.id IN (:ids)')
            ->setParameter('ids', $mediaIds)
            ->getQuery()
            ->getResult();

        $byId = [];

        foreach ($media as $item) {
            $byId[$item->id->toString()] = $item;
        }

        return $byId;
    }

    /**
     * @param array<string, mixed> $row
     * @return OnlineLiveRow
     */
    private static function row(array $row): array
    {
        return [
            'participant_id' => self::string($row['participant_id']) ?? '',
            'name' => self::string($row['name']) ?? '',
            'country' => self::string($row['country']),
            'entry_id' => self::string($row['entry_id']) ?? '',
            'online_entry_id' => self::string($row['online_entry_id']),
            'active_device_id' => self::string($row['active_device_id']),
            'ready_at_ms' => self::integer($row['ready_at_ms']),
            'clock_rtt_ms' => is_numeric($row['clock_rtt_ms']) ? (float) $row['clock_rtt_ms'] : null,
            'reported_measure' => self::string($row['reported_measure']),
            'reported_pieces' => self::integer($row['reported_pieces']),
            'review_status' => self::string($row['review_status']),
            'flags' => self::flags($row['flags']),
            'tap_id' => self::string($row['tap_id']),
            'tap_ms' => self::integer($row['tap_ms']),
            'tap_received_ms' => self::integer($row['tap_received_ms']),
            'candidate_id' => self::string($row['candidate_id']),
            'candidate_ms' => self::integer($row['candidate_ms']),
            'candidate_received_ms' => self::integer($row['candidate_received_ms']),
            'candidate_count' => self::integer($row['candidate_count']) ?? 0,
            'photo_id' => self::string($row['photo_id']),
            'photo_taken_ms' => self::integer($row['photo_taken_ms']),
        ];
    }

    private static function string(mixed $value): null|string
    {
        return is_string($value) ? $value : null;
    }

    private static function integer(mixed $value): null|int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return list<string>
     */
    public static function flags(mixed $value): array
    {
        $flags = is_string($value) ? json_decode($value, true) : null;

        if (!is_array($flags)) {
            return [];
        }

        return array_values(array_filter($flags, is_string(...)));
    }
}
