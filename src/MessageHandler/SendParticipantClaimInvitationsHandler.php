<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Exceptions\ClaimInvitationsNotAvailable;
use SpeedPuzzling\Web\Message\SendParticipantClaimInvitation;
use SpeedPuzzling\Web\Message\SendParticipantClaimInvitations;
use SpeedPuzzling\Web\Query\GetClaimInvitationAddresses;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\EmailSuppressionRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\ClaimInvitationsSent;
use SpeedPuzzling\Web\Services\ParticipantLinking\ParticipantLinks;
use SpeedPuzzling\Web\Value\ClaimInvitationSkip;
use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Claim invitations (docs/features/competitions-management/participant-linking.md §5.5, R8), under the event's
 * participants lock. Refused as a whole (ClaimInvitationsNotAvailable, nothing written) for an event that is not
 * publicly visible - its claim link opens nothing for the person - or uses online timing (its personal links, §5.6).
 *
 * Per person: CompetitionParticipant::claimInvitationRefusal() (on the list, not linked, an address, did not say no,
 * none in the last 24 hours), then the same per address over every row of the event (GetClaimInvitationAddresses - a
 * person removed and added again keeps their "no" and their 24 hours). An address that takes no e-mail from us
 * (email_suppression) is dropped silently and counted as sent (D10). Whoever passes gets a claim link if they have none, the invitation recorded
 * (`claim_invited_at`, a log row `invitation_sent` by the organiser) and one SendParticipantClaimInvitation queued - in
 * this transaction, so nothing goes out for a change set that rolled back. An id that is no participant of the event
 * counts as not on the list.
 *
 * Nothing depends on whether an address has an account (D10): the same e-mail for everybody.
 */
#[AsMessageHandler]
readonly final class SendParticipantClaimInvitationsHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private EmailSuppressionRepository $emailSuppressionRepository,
        private OnlineRoundRepository $onlineRoundRepository,
        private PlayerRepository $playerRepository,
        private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        private ParticipantLinks $participantLinks,
        private GetClaimInvitationAddresses $getClaimInvitationAddresses,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SendParticipantClaimInvitations $message): ClaimInvitationsSent
    {
        $competitionId = strtolower($message->competitionId);

        if ($this->isCompetitionPubliclyVisible->check($competitionId) === false) {
            throw new ClaimInvitationsNotAvailable(ClaimInvitationsNotAvailable::NOT_PUBLIC);
        }

        if ($this->onlineRoundRepository->ofCompetition($competitionId) !== []) {
            throw new ClaimInvitationsNotAvailable(ClaimInvitationsNotAvailable::ONLINE_TIMING);
        }

        $by = $this->playerRepository->get($message->byPlayerId);
        $now = $this->clock->now();
        $participants = $this->participantRepository->ofCompetitionForInvitations($competitionId, $message->participantIds);
        $skipped = [];
        $sent = 0;

        if ($message->participantIds !== null) {
            $asked = count(array_unique(array_map('strtolower', $message->participantIds)));

            if ($asked > count($participants)) {
                $skipped[ClaimInvitationSkip::NotOnList->value] = $asked - count($participants);
            }
        }

        // Per address over the event, removed rows included: a person removed and added again keeps their "no" and
        // their 24 hours (R8)
        $addresses = $this->getClaimInvitationAddresses->ofCompetition($competitionId);
        $invitedSince = $now->modify('-' . CompetitionParticipant::CLAIM_INVITATION_EVERY);

        foreach ($participants as $participant) {
            $reason = $participant->claimInvitationRefusal($now);
            $email = $participant->email;

            if ($reason === null && $email !== null) {
                if (isset($addresses['refused'][$email])) {
                    $reason = ClaimInvitationSkip::SaidNo;
                } elseif (isset($addresses['lastInvitedAt'][$email]) && $addresses['lastInvitedAt'][$email] > $invitedSince) {
                    $reason = ClaimInvitationSkip::InvitedRecently;
                }
            }

            if ($reason !== null || $email === null) {
                $reason ??= ClaimInvitationSkip::NoEmail;
                $skipped[$reason->value] = ($skipped[$reason->value] ?? 0) + 1;

                continue;
            }

            // An address that takes no e-mail from us (bounced, blocked, complained about an invitation): dropped
            // silently and counted as sent - nothing tells the organiser what we know of an address (D10). Nothing is
            // recorded for it either
            if ($this->emailSuppressionRepository->findByEmail($email)?->holdsBackClaimInvitations($now) === true) {
                $sent++;

                continue;
            }

            if ($participant->claimToken === null) {
                $participant->assignClaimToken(OnlineToken::generate());
            }

            $participant->recordClaimInvitation($now);
            $addresses['lastInvitedAt'][$email] = $now;
            $this->participantLinks->invitationSent($participant, $by, $now);
            $this->messageBus->dispatch(new SendParticipantClaimInvitation($participant->id->toString(), $email));
            $sent++;
        }

        return new ClaimInvitationsSent($sent, $skipped);
    }
}
