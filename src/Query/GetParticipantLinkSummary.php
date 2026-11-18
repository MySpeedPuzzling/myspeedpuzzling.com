<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Value\LinkRequestStatus;
use SpeedPuzzling\Web\Value\ParticipantLinkMethod;

/**
 * The counters of the toolkit hub's "Participants & profiles" card (docs/features/competitions-management/
 * participant-linking.md §7.5) - counts only, no identity: the event's people on the list (not removed), how many are
 * linked, how many linked themselves, and the link requests waiting for the organiser. The same numbers as the
 * participants sheet's People filters (ParticipantsSheetState::linkCounts()). And whether a round of the event uses
 * online timing - the card then hands out the personal links instead of claim links (§5.6). And how many people may
 * already have a profile (`suggestions`: the people GetParticipantLinkSuggestions has a candidate for - the same rules
 * and the same number as the sheet's "Suggestions" filter, as a subquery of this statement). And for "Send invitations to
 * everybody not linked with an e-mail" (§7.5, L4b) how many of the people not linked may get a claim invitation now and
 * why the others get none (CompetitionParticipant::claimInvitationRefusal() in SQL; whether an address takes our e-mail
 * is only known when sending, and never shown - D10), per address over every row of the event (R8). One statement.
 */
readonly final class GetParticipantLinkSummary
{
    public function __construct(
        private Connection $database,
        private GetParticipantLinkSuggestions $getParticipantLinkSuggestions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{people: int, linked: int, notLinked: int, linkedByThemselves: int, requests: int, suggestions: int, usesOnlineTiming: bool, invitable: int, withoutEmail: int, invitedRecently: int, saidNo: int}
     */
    public function of(string $competitionId): array
    {
        $suggestions = $this->getParticipantLinkSuggestions->sqlPeopleWithSuggestions();
        $own = implode(', ', array_map(
            static fn (ParticipantLinkMethod $method): string => "'" . $method->value . "'",
            array_values(array_filter(ParticipantLinkMethod::cases(), static fn (ParticipantLinkMethod $method): bool => $method->isThePlayersOwn())),
        ));

        /** @var array{people: int|string, linked: int|string, by_themselves: int|string, requests: int|string, suggestions: int|string, uses_online_timing: bool, invitable: int|string, without_email: int|string, invited_recently: int|string, said_no: int|string} $row */
        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT
    COUNT(*) FILTER (WHERE cp.id IS NOT NULL) AS people,
    COUNT(*) FILTER (WHERE cp.player_id IS NOT NULL) AS linked,
    COUNT(*) FILTER (WHERE cp.player_id IS NOT NULL AND cp.connected_via IN ({$own})) AS by_themselves,
    COUNT(*) FILTER (WHERE cp.player_id IS NULL AND cp.email IS NULL) AS without_email,
    COUNT(*) FILTER (WHERE cp.player_id IS NULL AND cp.email IS NOT NULL AND address.refused) AS said_no,
    COUNT(*) FILTER (WHERE cp.player_id IS NULL AND cp.email IS NOT NULL AND NOT address.refused
        AND address.last_invited_at > CAST(:invitedSince AS timestamp)) AS invited_recently,
    -- One e-mail per address (a family sharing one gets one)
    COUNT(DISTINCT cp.email) FILTER (WHERE cp.player_id IS NULL AND cp.email IS NOT NULL AND NOT address.refused
        AND (address.last_invited_at IS NULL OR address.last_invited_at <= CAST(:invitedSince AS timestamp))) AS invitable,
    (
        SELECT COUNT(*) FROM participant_link_request r
        WHERE r.competition_id = :competitionId AND r.status = :pending
    ) AS requests,
    {$suggestions} AS suggestions,
    EXISTS (
        SELECT 1 FROM online_round
        INNER JOIN competition_round online_timing_round ON online_timing_round.id = online_round.round_id
        WHERE online_timing_round.competition_id = :competitionId
    ) AS uses_online_timing
FROM competition_participant cp
-- The claim invitation rules per address over every row of the event, removed ones included (R8,
-- GetClaimInvitationAddresses): a person removed and added again keeps their "no" and their 24 hours
LEFT JOIN (
    SELECT email, bool_or(claim_invitations_refused_at IS NOT NULL) AS refused, MAX(claim_invited_at) AS last_invited_at
    FROM competition_participant
    WHERE competition_id = :competitionId AND email IS NOT NULL
    GROUP BY email
) address ON address.email = cp.email
WHERE cp.competition_id = :competitionId
    AND cp.deleted_at IS NULL
SQL,
            [
                'competitionId' => $competitionId,
                'pending' => LinkRequestStatus::Pending->value,
                'invitedSince' => $this->clock->now()->modify('-' . CompetitionParticipant::CLAIM_INVITATION_EVERY)->format('Y-m-d H:i:s'),
            ],
        );

        $people = (int) $row['people'];
        $linked = (int) $row['linked'];

        return [
            'people' => $people,
            'linked' => $linked,
            'notLinked' => $people - $linked,
            'linkedByThemselves' => (int) $row['by_themselves'],
            'requests' => (int) $row['requests'],
            'suggestions' => (int) $row['suggestions'],
            'usesOnlineTiming' => $row['uses_online_timing'],
            'invitable' => (int) $row['invitable'],
            'withoutEmail' => (int) $row['without_email'],
            'invitedRecently' => (int) $row['invited_recently'],
            'saidNo' => (int) $row['said_no'],
        ];
    }
}
