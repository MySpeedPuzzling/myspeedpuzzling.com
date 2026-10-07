<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\CompetitionEvent;
use SpeedPuzzling\Web\Results\EventAttendance;
use SpeedPuzzling\Web\Results\EventRegistration;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RegistrationAvailability;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use SpeedPuzzling\Web\Value\RoundTimezone;

/**
 * The "I'm going" state of a competition page - one statement for a signed-in player, none for a visitor.
 * Standalone events and series editions share it, as they share the whole join flow
 * (docs/features/competitions-management/participants.md).
 *
 * An event that manages registration (docs/features/competitions-management/registration.md) reads its registration
 * card in that one statement instead - for visitors too, the card shows the spots taken.
 */
readonly final class GetEventAttendance
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function forEvent(CompetitionEvent $event, null|string $playerId, bool $publiclyVisible): EventAttendance
    {
        if ($event->registrationManaged === false) {
            return $this->forPlayer($event->id, $playerId);
        }

        return $this->withRegistration($event, $playerId, $publiclyVisible);
    }

    public function forPlayer(string $competitionId, null|string $playerId): EventAttendance
    {
        if ($playerId === null) {
            return EventAttendance::notGoing();
        }

        $going = CompetitionParticipantGoing::sql('competition_participant');

        $query = <<<SQL
SELECT
    EXISTS (
        SELECT 1
        FROM competition_participant
        WHERE competition_id = :competitionId
        AND player_id = :playerId
        AND {$going}
    ) AS is_going,
    EXISTS (
        SELECT 1
        FROM competition_participant
        WHERE competition_id = :competitionId
        AND player_id IS NULL
        AND {$going}
    ) AS has_not_connected_participants
SQL;

        /** @var array{is_going: bool, has_not_connected_participants: bool} $row */
        $row = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
                'playerId' => $playerId,
            ])
            ->fetchAssociative();

        return new EventAttendance(
            isGoing: $row['is_going'],
            canChangeParticipant: $row['is_going'] && $row['has_not_connected_participants'],
        );
    }

    private function withRegistration(CompetitionEvent $event, null|string $playerId, bool $publiclyVisible): EventAttendance
    {
        $takenGoing = CompetitionParticipantGoing::sql('taken');
        $listedGoing = CompetitionParticipantGoing::sql('listed');
        $waitlisted = RegistrationStatus::Waitlisted->value;

        // The viewer's row: a going one first, when a player somehow holds two
        $query = <<<SQL
SELECT
    (SELECT COUNT(*) FROM competition_participant taken WHERE taken.competition_id = :competitionId AND {$takenGoing}) AS spots_taken,
    (
        SELECT COUNT(*)
        FROM competition_participant waiting
        WHERE waiting.competition_id = :competitionId
            AND waiting.deleted_at IS NULL
            AND waiting.registration_status = '{$waitlisted}'
    ) AS waitlisted_count,
    EXISTS (
        SELECT 1
        FROM competition_participant listed
        WHERE listed.competition_id = :competitionId
            AND listed.player_id IS NULL
            AND {$listedGoing}
    ) AS has_not_connected_participants,
    mine.id AS player_participant_id,
    mine.registration_status AS player_status,
    mine.source AS player_source,
    CASE WHEN mine.registration_status = '{$waitlisted}' THEN (
        SELECT COUNT(*)
        FROM competition_participant ahead
        WHERE ahead.competition_id = :competitionId
            AND ahead.deleted_at IS NULL
            AND ahead.registration_status = '{$waitlisted}'
            AND (COALESCE(ahead.registered_at, '-infinity'), ahead.id) <= (COALESCE(mine.registered_at, '-infinity'), mine.id)
    ) END AS waitlist_position
FROM (SELECT 1) AS viewer
LEFT JOIN LATERAL (
    SELECT cp.id, cp.registration_status, cp.source, cp.registered_at
    FROM competition_participant cp
    WHERE cp.competition_id = :competitionId
        AND cp.player_id = :playerId
        AND cp.deleted_at IS NULL
    ORDER BY cp.registration_status IS NOT DISTINCT FROM '{$waitlisted}', cp.id
    LIMIT 1
) AS mine ON TRUE
SQL;

        /**
         * @var array{
         *     spots_taken: int|string,
         *     waitlisted_count: int|string,
         *     has_not_connected_participants: bool,
         *     player_participant_id: null|string,
         *     player_status: null|string,
         *     player_source: null|string,
         *     waitlist_position: null|int|string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'competitionId' => $event->id,
                'playerId' => $playerId,
            ])
            ->fetchAssociative();

        $connected = $row['player_participant_id'] !== null;
        $status = $connected ? (RegistrationStatus::tryFrom($row['player_status'] ?? '') ?? RegistrationStatus::Reserved) : null;
        $isGoing = $connected && $status !== RegistrationStatus::Waitlisted;

        $now = $this->clock->now();
        $availability = $publiclyVisible
            ? RegistrationAvailability::ofWindow($now, $event->registrationOpensAt, $event->registrationClosesAt)
            : RegistrationAvailability::NotPublic;

        $registration = new EventRegistration(
            availability: $availability,
            capacity: $event->capacity,
            spotsTaken: (int) $row['spots_taken'],
            waitlistedCount: (int) $row['waitlisted_count'],
            opensAt: $event->registrationOpensAt,
            closesAt: $event->registrationClosesAt,
            timezone: self::timezoneOf($event),
            entryFeeText: $event->entryFeeText,
            paymentInstructions: $event->paymentInstructions,
            playerStatus: $status,
            playerWaitlistPosition: $row['waitlist_position'] !== null ? (int) $row['waitlist_position'] : null,
            playerSelfJoined: $row['player_source'] === ParticipantSource::SelfJoined->value,
            hasListedNames: $row['has_not_connected_participants'],
        );

        return new EventAttendance(
            isGoing: $isGoing,
            canChangeParticipant: $connected && $row['has_not_connected_participants'],
            registration: $registration,
        );
    }

    /**
     * The zone saved with the settings; an event made managed some other way reads in its country's zone.
     */
    public static function timezoneOf(CompetitionEvent $event): string
    {
        return RoundTimezone::resolve($event->registrationTimezone, $event->locationCountryCode?->name);
    }
}
