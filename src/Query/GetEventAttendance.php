<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\CompetitionEvent;
use SpeedPuzzling\Web\Results\EventAttendance;
use SpeedPuzzling\Web\Results\EventRegistration;
use SpeedPuzzling\Web\Results\ParticipantClaimCandidate;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\LinkRequestStatus;
use SpeedPuzzling\Web\Value\ParticipantLinkAction;
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
 *
 * Both statements also say whether the viewer follows the event or its series (`isFollowing`, the header's star of the
 * detail pages, docs/features/events-page/detail-pages.md) - no statement of its own.
 *
 * Of an event that may be online they also read the viewer's personal link (docs/features/online-competitions/
 * README.md §5.4, "Open my competition page") - in the same statement: the participant they are connected to and
 * going as, with its token, only when the event is online (EventIsOnline - an edition by its series), a round of it
 * uses online timing, and the viewer has confirmed the link as theirs for this very connection
 * (CompetitionParticipant::$onlineLinkConfirmedAt: opened it signed in, or joined by themselves) - a participant claimed
 * from the organiser's list never hands out its link. A one-time event that is not online runs the statements it
 * always ran.
 *
 * And the viewer's pending link request (participant-linking.md §6.1 "Waiting for the organiser") - in the same statement.
 *
 * With `$withClaimMatch` (the event and edition pages) also the "Is this you?" card (participant-linking.md §6.1, §8.3) -
 * in the same statement again (+0): the one active unlinked participant whose name key (the join page's -
 * GetCompetitionParticipants::sqlNameKey()) and country fit the viewer's profile, or - `$focusParticipantId`, a scanned
 * name tag's `?participant=` (§6.4, R12) - that participant whatever its name. Never a refused pair, never for a player
 * the organiser refused 3 times at the event (D11). Only the organiser's record is read of the participant; of the
 * player table only the viewer's own name and country.
 */
readonly final class GetEventAttendance
{
    // The personal link's columns, from the `online_link` join (sqlOnlineLinkJoin())
    private const string SQL_ONLINE_LINK_COLUMNS = ",\n    online_link.id AS online_participant_id,\n    online_link.online_token AS online_token";

    // The "Is this you?" match's columns, from the `claim_match` join (sqlClaimMatchJoin())
    private const string SQL_CLAIM_MATCH_COLUMNS = ",\n    claim_match.match_count AS claim_match_count,\n    claim_match.participant_id AS claim_participant_id,\n    claim_match.name AS claim_participant_name,\n    claim_match.country AS claim_participant_country";

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param bool $withClaimMatch the "Is this you?" card's match (event and edition pages) - only on a publicly visible event
     * @param null|string $focusParticipantId a scanned name tag's participant (`?participant=`, a valid UUID) - the card for it
     */
    public function forEvent(
        CompetitionEvent $event,
        null|string $playerId,
        bool $publiclyVisible,
        bool $withClaimMatch = false,
        null|string $focusParticipantId = null,
    ): EventAttendance {
        $withClaimMatch = $withClaimMatch && $publiclyVisible;

        if ($event->registrationManaged === false) {
            return $this->forPlayer($event->id, $playerId, self::mayBeOnline($event), $withClaimMatch, $focusParticipantId);
        }

        return $this->withRegistration($event, $playerId, $publiclyVisible, $withClaimMatch, $focusParticipantId);
    }

    /**
     * Online by its own flag, or an edition - online by its series' flag, which the statement reads (EventIsOnline)
     */
    private static function mayBeOnline(CompetitionEvent $event): bool
    {
        return $event->isOnline || $event->seriesId !== null;
    }

    /**
     * @param bool $withOnlineLink an event that may be online - read the viewer's personal link too
     * @param bool $withClaimMatch the "Is this you?" card's match - the caller knows the event is publicly visible
     */
    public function forPlayer(
        string $competitionId,
        null|string $playerId,
        bool $withOnlineLink = false,
        bool $withClaimMatch = false,
        null|string $focusParticipantId = null,
    ): EventAttendance {
        if ($playerId === null) {
            return EventAttendance::notGoing();
        }

        $going = CompetitionParticipantGoing::sql('competition_participant');
        $onlineColumns = '';
        $joins = [];
        $parameters = [
            'competitionId' => $competitionId,
            'playerId' => $playerId,
        ];

        if ($withOnlineLink) {
            $onlineColumns = self::SQL_ONLINE_LINK_COLUMNS;
            $joins[] = $this->sqlOnlineLinkJoin();
        }

        $focusParticipantId = self::validFocus($focusParticipantId);

        if ($withClaimMatch) {
            $onlineColumns .= self::SQL_CLAIM_MATCH_COLUMNS;
            $joins[] = $this->sqlClaimMatchJoin($focusParticipantId !== null);

            if ($focusParticipantId !== null) {
                $parameters['focusParticipantId'] = $focusParticipantId;
            }
        }

        $onlineJoin = $joins !== [] ? "\nFROM (SELECT 1) AS viewer\n" . implode("\n", $joins) : '';

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
    ) AS has_not_connected_participants,
    {$this->sqlIsFollowing()} AS is_following,
    {$this->sqlPendingRequestName()} AS pending_request_participant_name{$onlineColumns}{$onlineJoin}
SQL;

        /** @var array{is_going: bool, has_not_connected_participants: bool, is_following: bool, pending_request_participant_name: null|string, online_participant_id?: null|string, online_token?: null|string, claim_match_count?: null|int|string, claim_participant_id?: null|string, claim_participant_name?: null|string, claim_participant_country?: null|string} $row */
        $row = $this->database
            ->executeQuery($query, $parameters)
            ->fetchAssociative();

        return new EventAttendance(
            isGoing: $row['is_going'],
            canChangeParticipant: $row['is_going'] && $row['has_not_connected_participants'],
            isFollowing: $row['is_following'],
            onlineParticipantId: self::lowerOrNull($row['online_participant_id'] ?? null),
            onlineToken: $row['online_token'] ?? null,
            pendingRequestParticipantName: $row['pending_request_participant_name'],
            claimCandidate: $row['is_going'] === false && $row['pending_request_participant_name'] === null
                ? self::claimCandidate(
                    $row['claim_match_count'] ?? null,
                    $row['claim_participant_id'] ?? null,
                    $row['claim_participant_name'] ?? null,
                    $row['claim_participant_country'] ?? null,
                    $focusParticipantId !== null,
                )
                : null,
        );
    }

    /**
     * The name (as the organiser wrote it) of the participant the viewer's pending link request is for - "Waiting for the
     * organiser to confirm you are %name%" (docs/features/competitions-management/participant-linking.md §6.1). One per
     * player and event (ParticipantLinks::request()); a visitor's `:playerId` is null - none.
     */
    private function sqlPendingRequestName(): string
    {
        $pending = LinkRequestStatus::Pending->value;

        return <<<SQL
(
        SELECT pending_participant.name
        FROM participant_link_request pending_request
        INNER JOIN competition_participant pending_participant ON pending_participant.id = pending_request.participant_id
        WHERE pending_request.competition_id = :competitionId
            AND pending_request.player_id = :playerId
            AND pending_request.status = '{$pending}'
        ORDER BY pending_request.created_at DESC
        LIMIT 1
    )
SQL;
    }

    /**
     * The viewer's personal link: their participant row that is going, has a token and whose link they confirmed for
     * this very connection (CompetitionParticipant::hasConfirmedOnlineLink()), while the event is online (EventIsOnline)
     * and a round of it uses online timing (docs/features/online-competitions/README.md §5.4). Joined to `viewer`.
     */
    private function sqlOnlineLinkJoin(): string
    {
        $going = CompetitionParticipantGoing::sql('linked');
        $isOnline = EventIsOnline::sql('online_event', 'online_series');

        return <<<SQL
LEFT JOIN LATERAL (
    SELECT linked.id, linked.online_token
    FROM competition_participant linked
    WHERE linked.competition_id = :competitionId
        AND linked.player_id = :playerId
        AND {$going}
        AND linked.online_token IS NOT NULL
        AND linked.connected_at IS NOT NULL
        AND linked.online_link_confirmed_at >= linked.connected_at
        AND EXISTS (
            SELECT 1
            FROM competition online_event
            LEFT JOIN competition_series online_series ON online_series.id = online_event.series_id
            WHERE online_event.id = :competitionId
                AND {$isOnline}
        )
        AND EXISTS (
            SELECT 1
            FROM online_round
            INNER JOIN competition_round online_timing_round ON online_timing_round.id = online_round.round_id
            WHERE online_timing_round.competition_id = :competitionId
        )
    ORDER BY linked.id
    LIMIT 1
) AS online_link ON TRUE
SQL;
    }

    private static function lowerOrNull(null|string $id): null|string
    {
        return $id !== null ? strtolower($id) : null;
    }

    private function withRegistration(
        CompetitionEvent $event,
        null|string $playerId,
        bool $publiclyVisible,
        bool $withClaimMatch,
        null|string $focusParticipantId,
    ): EventAttendance {
        $takenGoing = CompetitionParticipantGoing::sql('taken');
        $listedGoing = CompetitionParticipantGoing::sql('listed');
        $waitlisted = RegistrationStatus::Waitlisted->value;
        [$onlineColumns, $onlineJoin] = self::mayBeOnline($event)
            ? [self::SQL_ONLINE_LINK_COLUMNS, "\n" . $this->sqlOnlineLinkJoin()]
            : ['', ''];
        $parameters = [
            'competitionId' => $event->id,
            'playerId' => $playerId,
        ];

        // A visitor has no name to match - the registration card's statement stays as it was for them
        $focusParticipantId = self::validFocus($focusParticipantId);
        $withClaimMatch = $withClaimMatch && $playerId !== null;

        if ($withClaimMatch) {
            $onlineColumns .= self::SQL_CLAIM_MATCH_COLUMNS;
            $onlineJoin .= "\n" . $this->sqlClaimMatchJoin($focusParticipantId !== null);

            if ($focusParticipantId !== null) {
                $parameters['focusParticipantId'] = $focusParticipantId;
            }
        }

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
    (
        SELECT event_series.location_country_code
        FROM competition this_event
        INNER JOIN competition_series event_series ON event_series.id = this_event.series_id
        WHERE this_event.id = :competitionId
    ) AS series_country_code,
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
    ) END AS waitlist_position,
    {$this->sqlIsFollowing()} AS is_following,
    {$this->sqlPendingRequestName()} AS pending_request_participant_name{$onlineColumns}
FROM (SELECT 1) AS viewer
LEFT JOIN LATERAL (
    SELECT cp.id, cp.registration_status, cp.source, cp.registered_at
    FROM competition_participant cp
    WHERE cp.competition_id = :competitionId
        AND cp.player_id = :playerId
        AND cp.deleted_at IS NULL
    ORDER BY cp.registration_status IS NOT DISTINCT FROM '{$waitlisted}', cp.id
    LIMIT 1
) AS mine ON TRUE{$onlineJoin}
SQL;

        /**
         * @var array{
         *     spots_taken: int|string,
         *     waitlisted_count: int|string,
         *     has_not_connected_participants: bool,
         *     series_country_code: null|string,
         *     player_participant_id: null|string,
         *     player_status: null|string,
         *     player_source: null|string,
         *     waitlist_position: null|int|string,
         *     is_following: bool,
         *     pending_request_participant_name: null|string,
         *     online_participant_id?: null|string,
         *     online_token?: null|string,
         *     claim_match_count?: null|int|string,
         *     claim_participant_id?: null|string,
         *     claim_participant_name?: null|string,
         *     claim_participant_country?: null|string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, $parameters)
            ->fetchAssociative();

        $connected = $row['player_participant_id'] !== null;
        $status = $connected ? (RegistrationStatus::tryFrom($row['player_status'] ?? '') ?? RegistrationStatus::Reserved) : null;
        $isGoing = $connected && $status !== RegistrationStatus::Waitlisted;

        $timezone = self::timezoneOf($event, $row['series_country_code']);
        $availability = $publiclyVisible
            ? RegistrationAvailability::ofWindow(
                $this->clock->now(),
                $event->registrationOpensAt,
                $event->registrationClosesAt,
                // Without a closing time of its own, registration closes when the event is over
                RegistrationAvailability::eventEndsAt($event->dateFrom, $event->dateTo, $timezone),
            )
            : RegistrationAvailability::NotPublic;

        $registration = new EventRegistration(
            availability: $availability,
            capacity: $event->capacity,
            spotsTaken: (int) $row['spots_taken'],
            waitlistedCount: (int) $row['waitlisted_count'],
            opensAt: $event->registrationOpensAt,
            closesAt: $event->registrationClosesAt,
            timezone: $timezone,
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
            isFollowing: $row['is_following'],
            onlineParticipantId: self::lowerOrNull($row['online_participant_id'] ?? null),
            onlineToken: $row['online_token'] ?? null,
            pendingRequestParticipantName: $row['pending_request_participant_name'],
            claimCandidate: $isGoing === false && $row['pending_request_participant_name'] === null
                ? self::claimCandidate(
                    $row['claim_match_count'] ?? null,
                    $row['claim_participant_id'] ?? null,
                    $row['claim_participant_name'] ?? null,
                    $row['claim_participant_country'] ?? null,
                    $focusParticipantId !== null,
                )
                : null,
        );
    }

    /**
     * The card's participant (participant-linking.md §6.1): exactly one - two people of that name are the join page's
     * pick ("2 people on the list are called …").
     *
     * The match columns are absent (null) when the statement did not read them.
     */
    private static function claimCandidate(
        null|int|string $count,
        null|string $id,
        null|string $name,
        null|string $country,
        bool $fromScan,
    ): null|ParticipantClaimCandidate {
        if ((int) $count !== 1 || $id === null || $name === null) {
            return null;
        }

        return new ParticipantClaimCandidate(
            participantId: strtolower($id),
            name: $name,
            country: CountryCode::fromCode($country),
            fromScan: $fromScan,
        );
    }

    private static function validFocus(null|string $focusParticipantId): null|string
    {
        return $focusParticipantId !== null && Uuid::isValid($focusParticipantId) ? strtolower($focusParticipantId) : null;
    }

    /**
     * participant-linking.md §8.3: the active unlinked participants of the event that are the viewer's by name (the join
     * page's name key, a compatible country) - or, for a scanned tag, the one asked for - counted, with the first one's
     * columns: the card shows only when exactly one came back. A refused pair (D11) is none, and a player the organiser
     * refused 3 times at the event gets no card at all. Joined to `viewer`; aggregate, so always one row.
     */
    private function sqlClaimMatchJoin(bool $focused): string
    {
        $going = CompetitionParticipantGoing::sql('candidate');
        $refusing = GetRefusedParticipantLinks::sqlRefusingActions();
        $declined = ParticipantLinkAction::Declined->value;
        $notThisPerson = ParticipantLinkAction::NotThisPerson->value;
        $limit = GetRefusedParticipantLinks::REFUSALS_UNTIL_NO_MORE_CLAIMS;
        $match = $focused
            ? 'candidate.id = :focusParticipantId'
            : GetCompetitionParticipants::sqlNameKey('candidate.name') . ' = ' . GetCompetitionParticipants::sqlNameKey('claimant.name') . "
        AND (candidate.country IS NULL OR claimant.country IS NULL OR candidate.country = claimant.country)";

        return <<<SQL
LEFT JOIN LATERAL (
    SELECT
        COUNT(*) AS match_count,
        MIN(candidate.id::text) AS participant_id,
        MIN(candidate.name) AS name,
        MIN(candidate.country) AS country
    FROM competition_participant candidate
    INNER JOIN player claimant ON claimant.id = :playerId
    WHERE candidate.competition_id = :competitionId
        AND candidate.player_id IS NULL
        AND {$going}
        AND {$match}
        AND NOT EXISTS (
            SELECT 1
            FROM participant_link_log refusal
            WHERE refusal.competition_id = :competitionId
                AND refusal.participant_id = candidate.id
                AND refusal.player_id = :playerId
                AND refusal.action IN ({$refusing})
        )
        AND (
            SELECT COUNT(*)
            FROM participant_link_log organiser_refusal
            WHERE organiser_refusal.competition_id = :competitionId
                AND organiser_refusal.player_id = :playerId
                AND organiser_refusal.action IN ('{$declined}', '{$notThisPerson}')
        ) < {$limit}
) AS claim_match ON TRUE
SQL;
    }

    /**
     * What the page's star acts on: an edition's star follows its series, so an edition is "following" only when the
     * viewer follows the series (a follow of the edition itself does not count); a one-time event when it is followed.
     * A visitor's `:playerId` is null - never true.
     */
    private function sqlIsFollowing(): string
    {
        return <<<SQL
EXISTS (
        SELECT 1
        FROM competition f_c
        JOIN followed_competition fc ON fc.player_id = :playerId
            AND (
                (f_c.series_id IS NOT NULL AND fc.series_id = f_c.series_id)
                OR (f_c.series_id IS NULL AND fc.competition_id = f_c.id)
            )
        WHERE f_c.id = :competitionId
    )
SQL;
    }

    /**
     * The zone saved with the settings; an event made managed some other way reads in its (or its series') country's
     * zone - the same as Competition::registrationZone() and the participants export.
     */
    private static function timezoneOf(CompetitionEvent $event, null|string $seriesCountryCode): string
    {
        return RoundTimezone::resolve($event->registrationTimezone, $event->locationCountryCode?->name, $seriesCountryCode);
    }
}
