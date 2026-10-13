<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\OnlineReviewAssessment;

/**
 * What the review of an online round reads (docs/features/online-competitions/README.md §5.9), in a constant number of
 * statements whatever the number of participants:
 * - entries(): every participant entered in the round and going (CompetitionParticipantGoing), their official result
 *   (a solo round's entry carries it) and their online entry - one statement;
 * - events(): the entries' events but the upload reports (the media rows say what was uploaded) - one statement; a
 *   Finished tap (or a finish candidate) that arrived OnlineReviewAssessment::LATE_ARRIVAL_MS or more after its moment
 *   says whether another event of the entry reached the server between the moment and its arrival (`heard_meanwhile`,
 *   HEARD_GRACE_MS of slack on both sides - the phone was online and sending, yet this one came only later);
 * - media(): one entry's evidence rows, for their short-lived URLs (the entry's detail) - one statement.
 * Both lists can be narrowed to one participant / one online entry (the detail).
 *
 * @phpstan-type OnlineReviewEntryRow array{
 *     participant_id: string,
 *     name: string,
 *     country: null|string,
 *     entry_id: string,
 *     result_seconds: null|int,
 *     result_pieces_placed: null|int,
 *     result_did_not_start: bool,
 *     online_entry_id: null|string,
 *     ready_at_ms: null|int,
 *     active_device_id: null|string,
 *     reported_measure: null|string,
 *     reported_pieces: null|int,
 *     review_status: null|string,
 *     confirmed_moment_ms: null|int,
 *     confirmed_source: null|string,
 *     reviewed_at_ms: null|int,
 *     flags: list<string>,
 * }
 * @phpstan-type OnlineReviewEventRow array{
 *     online_entry_id: string,
 *     id: string,
 *     type: string,
 *     device_id: string,
 *     client_ms: int,
 *     received_at_ms: int,
 *     payload: array<string, mixed>,
 *     heard_meanwhile: bool,
 * }
 */
readonly final class GetOnlineReviewData
{
    public function __construct(
        private Connection $database,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<OnlineReviewEntryRow>
     */
    public function entries(string $roundId, null|string $participantId = null): array
    {
        if (Uuid::isValid($roundId) === false || ($participantId !== null && Uuid::isValid($participantId) === false)) {
            return [];
        }

        $going = CompetitionParticipantGoing::sql('cp');
        $oneParticipant = $participantId === null ? '' : 'AND cp.id = :participantId';
        $parameters = ['roundId' => strtolower($roundId)];

        if ($participantId !== null) {
            $parameters['participantId'] = strtolower($participantId);
        }

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    cp.id AS participant_id,
    cp.name,
    cp.country,
    cpr.id AS entry_id,
    cpr.result_seconds,
    cpr.result_pieces_placed,
    cpr.result_did_not_start,
    oe.id AS online_entry_id,
    (EXTRACT(EPOCH FROM oe.ready_at) * 1000)::bigint AS ready_at_ms,
    oe.active_device_id,
    oe.reported_measure,
    oe.reported_pieces,
    oe.review_status,
    oe.confirmed_moment_ms,
    oe.confirmed_source,
    (EXTRACT(EPOCH FROM oe.reviewed_at) * 1000)::bigint AS reviewed_at_ms,
    oe.flags
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
LEFT JOIN online_entry oe ON oe.entry_id = cpr.id
WHERE cpr.round_id = :roundId
    AND {$going}
    {$oneParticipant}
ORDER BY lower(cp.name), cp.id
SQL,
            $parameters,
        );

        return array_map(static fn (array $row): array => [
            'participant_id' => self::string($row['participant_id']) ?? '',
            'name' => self::string($row['name']) ?? '',
            'country' => self::string($row['country']),
            'entry_id' => self::string($row['entry_id']) ?? '',
            'result_seconds' => self::integer($row['result_seconds']),
            'result_pieces_placed' => self::integer($row['result_pieces_placed']),
            'result_did_not_start' => $row['result_did_not_start'] === true,
            'online_entry_id' => self::string($row['online_entry_id']),
            'ready_at_ms' => self::integer($row['ready_at_ms']),
            'active_device_id' => self::string($row['active_device_id']),
            'reported_measure' => self::string($row['reported_measure']),
            'reported_pieces' => self::integer($row['reported_pieces']),
            'review_status' => self::string($row['review_status']),
            'confirmed_moment_ms' => self::integer($row['confirmed_moment_ms']),
            'confirmed_source' => self::string($row['confirmed_source']),
            'reviewed_at_ms' => self::integer($row['reviewed_at_ms']),
            'flags' => GetOnlineLiveParticipants::flags($row['flags']),
        ], $rows);
    }

    /**
     * @return list<OnlineReviewEventRow> by online entry, then moment
     */
    public function events(string $roundId, null|string $onlineEntryId = null): array
    {
        if (Uuid::isValid($roundId) === false || ($onlineEntryId !== null && Uuid::isValid($onlineEntryId) === false)) {
            return [];
        }

        $oneEntry = $onlineEntryId === null ? '' : 'AND oe.id = :onlineEntryId';
        $parameters = [
            'roundId' => strtolower($roundId),
            'uploads' => OnlineEventType::MediaUploaded->value,
            'finishTypes' => [OnlineEventType::FinishButton->value, OnlineEventType::FinishCandidate->value],
            'lateMs' => OnlineReviewAssessment::LATE_ARRIVAL_MS,
            'graceMs' => OnlineReviewAssessment::HEARD_GRACE_MS,
        ];
        $types = [
            'finishTypes' => ArrayParameterType::STRING,
            'lateMs' => ParameterType::INTEGER,
            'graceMs' => ParameterType::INTEGER,
        ];

        if ($onlineEntryId !== null) {
            $parameters['onlineEntryId'] = strtolower($onlineEntryId);
        }

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    e.online_entry_id,
    e.id,
    e.type,
    e.device_id,
    e.client_ms,
    e.received_at_ms,
    e.payload,
    CASE
        WHEN e.type IN (:finishTypes) AND e.received_at_ms - e.client_ms >= :lateMs THEN EXISTS (
            SELECT 1
            FROM online_entry_event heard
            WHERE heard.online_entry_id = e.online_entry_id
                AND heard.id <> e.id
                AND heard.received_at_ms > e.client_ms + :graceMs
                AND heard.received_at_ms < e.received_at_ms - :graceMs
        )
        ELSE FALSE
    END AS heard_meanwhile
FROM online_entry_event e
INNER JOIN online_entry oe ON oe.id = e.online_entry_id
INNER JOIN competition_participant_round cpr ON cpr.id = oe.entry_id
WHERE cpr.round_id = :roundId
    AND e.type <> :uploads
    {$oneEntry}
ORDER BY e.online_entry_id, e.client_ms, e.id
SQL,
            $parameters,
            $types,
        );

        return array_map(static fn (array $row): array => [
            'online_entry_id' => self::string($row['online_entry_id']) ?? '',
            'id' => self::string($row['id']) ?? '',
            'type' => self::string($row['type']) ?? '',
            'device_id' => self::string($row['device_id']) ?? '',
            'client_ms' => self::integer($row['client_ms']) ?? 0,
            'received_at_ms' => self::integer($row['received_at_ms']) ?? 0,
            'payload' => self::payload($row['payload']),
            'heard_meanwhile' => $row['heard_meanwhile'] === true,
        ], $rows);
    }

    /**
     * One entry's evidence, by the moment it was taken
     *
     * @return list<OnlineEntryMedia>
     */
    public function media(string $onlineEntryId): array
    {
        if (Uuid::isValid($onlineEntryId) === false) {
            return [];
        }

        /** @var list<OnlineEntryMedia> $media */
        $media = $this->entityManager->createQueryBuilder()
            ->select('media')
            ->from(OnlineEntryMedia::class, 'media')
            ->where('media.onlineEntry = :onlineEntryId')
            ->setParameter('onlineEntryId', strtolower($onlineEntryId))
            ->orderBy('media.takenMs', 'ASC')
            ->addOrderBy('media.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $media;
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
     * An event's payload as stored (jsonb, an object) - anything else is no payload
     *
     * @return array<string, mixed>
     */
    private static function payload(mixed $value): array
    {
        $payload = is_string($value) ? json_decode($value, true) : null;

        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            return [];
        }

        /** @var array<string, mixed> $payload a JSON object decodes to string keys */
        return $payload;
    }
}
