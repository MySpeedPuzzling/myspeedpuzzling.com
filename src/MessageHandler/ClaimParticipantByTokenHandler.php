<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Message\ClaimParticipantByToken;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\CompetitionSelfJoin;
use SpeedPuzzling\Web\Services\ParticipantLinking\ParticipantLinks;
use SpeedPuzzling\Web\Value\ClaimLinkOutcome;
use SpeedPuzzling\Web\Value\ParticipantLinkMethod;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A personal claim link opened signed in (docs/features/competitions-management/participant-linking.md §5.2): the
 * organiser's token is proof - it links at once under every claim policy and never consults the policy or the refused
 * pairs. Checked in this order, everything before the first change (the rolled-back-handler rule):
 * unknown / cleared token, removed participant, another event's, or an event not publicly visible → not_valid;
 * linked to this player → already_yours; linked to another account → belongs_to_other (the page offers the dispute);
 * the player on the event's list as somebody else and no `switch` → is_other_participant (the page offers it); the
 * switch would let go of their own paid registration on an event managing registration without their yes →
 * release_not_confirmed;
 * else the player's other rows are let go of (CompetitionSelfJoin's rules), the row is linked (`claim_link`) and - like
 * the online personal link - the row counts as the player's own: the event page hands them the online competition's
 * personal link (CompetitionSelfJoin::handOwnLink(), §5.6).
 */
#[AsMessageHandler]
readonly final class ClaimParticipantByTokenHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private PlayerRepository $playerRepository,
        private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        private GetCompetitionParticipants $getCompetitionParticipants,
        private CompetitionSelfJoin $competitionSelfJoin,
        private ParticipantLinks $participantLinks,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ClaimParticipantByToken $message): ClaimLinkOutcome
    {
        try {
            $participant = $this->participantRepository->getByClaimToken($message->token);
        } catch (CompetitionParticipantNotFound) {
            return ClaimLinkOutcome::NotValid;
        }

        // As it is now, under the lock - not as an earlier read of this request left it
        $this->participantRepository->refresh($participant);
        $competitionId = $participant->competition->id->toString();

        if (
            $participant->claimToken !== $message->token
            || $competitionId !== strtolower($message->competitionId)
            || $participant->isDeleted()
            || $this->isCompetitionPubliclyVisible->check($competitionId) === false
        ) {
            return ClaimLinkOutcome::NotValid;
        }

        $player = $this->playerRepository->get($message->playerId);

        if ($participant->isConnectedTo($player)) {
            return ClaimLinkOutcome::AlreadyYours;
        }

        if ($participant->player !== null) {
            return ClaimLinkOutcome::BelongsToOther;
        }

        $otherRows = $this->getCompetitionParticipants->getPlayerConnections($competitionId, $player->id->toString());

        if ($message->switch === false && $otherRows !== []) {
            return ClaimLinkOutcome::IsOtherParticipant;
        }

        // The switch lets go of the player's own rows - a paid registration of their own only on their explicit yes,
        // read now under the lock (it may have become paid after the page was shown). Only while the event manages
        // registration: a paid status left from before means nothing (the ParticipantClaims rule)
        if (
            $message->releasesOwnRegistration === false
            && $participant->competition->registrationManaged
            && $this->holdsOwnPaidRegistration($otherRows)
        ) {
            return ClaimLinkOutcome::ReleaseNotConfirmed;
        }

        $this->competitionSelfJoin->releaseOtherParticipants($competitionId, $player, keep: $participant);
        $this->participantLinks->link($participant, $player, ParticipantLinkMethod::ClaimLink, $player, $this->clock->now());
        $this->competitionSelfJoin->handOwnLink($participant);

        return ClaimLinkOutcome::Linked;
    }

    /**
     * @param array<string> $participantIds the player's other rows of the event
     */
    private function holdsOwnPaidRegistration(array $participantIds): bool
    {
        foreach ($participantIds as $participantId) {
            $own = $this->participantRepository->get($participantId);
            // As it is now, under the lock - it may have become paid since this request first read it
            $this->participantRepository->refresh($own);

            if ($own->source === ParticipantSource::SelfJoined && $own->registrationStatus === RegistrationStatus::Paid) {
                return true;
            }
        }

        return false;
    }
}
