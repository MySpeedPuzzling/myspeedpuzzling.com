<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantLinking;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\GetRefusedParticipantLinks;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Services\CompetitionSelfJoin;
use SpeedPuzzling\Web\Value\ClaimOutcome;
use SpeedPuzzling\Web\Value\LinkRequestKind;
use SpeedPuzzling\Web\Value\ParticipantClaimPolicy;
use SpeedPuzzling\Web\Value\ParticipantLinkMethod;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RegistrationStatus;

/**
 * The one policy decision of a claim - a player saying "this participant is me" from a name, without proof
 * (docs/features/competitions-management/participant-linking.md §5.1, D2, D3, D11, D13). Used by JoinCompetition's pick
 * and ClaimParticipant; called under CompetitionParticipantsLock by handlers that checked the event is publicly visible.
 *
 * Checked in this order, everything before the first change (the rolled-back-handler rule):
 * removed / another event's participant → not_available; linked to this player → already_yours; a refused pair or the
 * player's 3 refusals here → ask_organiser; the player's own paid registration would go without their yes →
 * release_not_confirmed; linked to another player → a dispute request; policy `open` → the player's other rows let go of
 * (CompetitionSelfJoin's rules) and linked (`claim`); policy `approval` → a request (released at approval, R1).
 */
readonly final class ParticipantClaims
{
    public function __construct(
        private ParticipantLinks $participantLinks,
        private GetRefusedParticipantLinks $getRefusedParticipantLinks,
        private GetCompetitionParticipants $getCompetitionParticipants,
        private CompetitionParticipantRepository $participantRepository,
        private CompetitionSelfJoin $competitionSelfJoin,
        private ClockInterface $clock,
    ) {
    }

    public function claim(
        Competition $competition,
        Player $player,
        CompetitionParticipant $participant,
        bool $releasesOwnRegistration,
    ): ClaimOutcome {
        if ($participant->competition->id->equals($competition->id) === false || $participant->isDeleted()) {
            return ClaimOutcome::NotAvailable;
        }

        if ($participant->isConnectedTo($player)) {
            return ClaimOutcome::AlreadyYours;
        }

        $competitionId = $competition->id->toString();
        $refusals = $this->getRefusedParticipantLinks->forClaim($competitionId, $participant->id->toString(), $player->id->toString());

        if ($refusals['refusedPair'] || $refusals['organiserRefusals'] >= GetRefusedParticipantLinks::REFUSALS_UNTIL_NO_MORE_CLAIMS) {
            return ClaimOutcome::AskOrganiser;
        }

        // Only while the event manages registration: a paid status left from before management was switched off means
        // nothing - and the page has no box to say yes in
        if ($releasesOwnRegistration === false && $competition->registrationManaged && $this->holdsOwnPaidRegistration($competitionId, $player, $participant)) {
            return ClaimOutcome::ReleaseNotConfirmed;
        }

        $now = $this->clock->now();

        if ($participant->player !== null) {
            $this->participantLinks->request($participant, $player, LinkRequestKind::Dispute, $releasesOwnRegistration, $now);

            return ClaimOutcome::DisputeSent;
        }

        if ($competition->participantClaimPolicy() === ParticipantClaimPolicy::Approval) {
            $this->participantLinks->request($participant, $player, LinkRequestKind::Claim, $releasesOwnRegistration, $now);

            return ClaimOutcome::Requested;
        }

        $this->competitionSelfJoin->releaseOtherParticipants($competitionId, $player, keep: $participant);
        $this->participantLinks->link($participant, $player, ParticipantLinkMethod::Claim, $player, $now);

        return ClaimOutcome::Linked;
    }

    /**
     * The player's own row of the event holds a paid registration - a switch lets go of it, only on their explicit yes
     * (registration.md, `release_paid`).
     */
    private function holdsOwnPaidRegistration(string $competitionId, Player $player, CompetitionParticipant $keep): bool
    {
        foreach ($this->getCompetitionParticipants->getPlayerConnections($competitionId, $player->id->toString()) as $participantId) {
            if ($participantId === $keep->id->toString()) {
                continue;
            }

            $own = $this->participantRepository->get($participantId);

            if ($own->source === ParticipantSource::SelfJoined && $own->registrationStatus === RegistrationStatus::Paid) {
                return true;
            }
        }

        return false;
    }
}
