<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Message\LeaveCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\OfficialResultsGuard;
use SpeedPuzzling\Web\Services\ParticipantLinking\ParticipantLinks;
use SpeedPuzzling\Web\Value\ParticipantLinkAction;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\PartnerListingClosedReason;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class LeaveCompetitionHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private GetCompetitionParticipants $getCompetitionParticipants,
        private ClockInterface $clock,
        private OfficialResultsGuard $officialResultsGuard,
        private PartnerListingRepository $partnerListingRepository,
        private PlayerRepository $playerRepository,
        private ParticipantLinks $participantLinks,
    ) {
    }

    /**
     * @return bool false = "Not me" refused (not a participant of this event linked to the player by the organiser),
     *     nothing changed; true otherwise
     */
    public function __invoke(LeaveCompetition $message): bool
    {
        if ($message->notMeParticipantId !== null) {
            return $this->notMe($message, $message->notMeParticipantId);
        }

        // Every row, not just one — a player who joined twice would otherwise still be going
        $participantIds = $this->getCompetitionParticipants->getPlayerConnections($message->competitionId, $message->playerId);
        $player = $participantIds !== [] ? $this->playerRepository->get($message->playerId) : null;

        foreach ($participantIds as $participantId) {
            $participant = $this->participantRepository->get($participantId);

            // A row holding official results is the organiser's record - the player only lets go of it
            if ($participant->source === ParticipantSource::SelfJoined && $this->officialResultsGuard->participantHasOfficialData($participantId) === false) {
                $participant->softDelete($this->clock->now());
                $this->participantLinks->participantRemoved($participant, $this->clock->now());
            } else {
                // Not going is no "this is not me" - the player may pick the name again (a refused pair is L2's "Not me")
                $this->participantLinks->unlink($participant, ParticipantLinkAction::Unlinked, $player, $this->clock->now());
            }
        }

        // Not going any more = not looking for a partner there either (docs/features/partner-finder.md D7)
        $this->partnerListingRepository
            ->findOpenFor($message->competitionId, $message->playerId)
            ?->close(PartnerListingClosedReason::NotGoing, $this->clock->now());

        return true;
    }

    /**
     * "Not me" (participant-linking.md §6.6, D11): the participant the organiser (sheet or import) linked the player to is
     * let go of as `not_me` - a refused pair, so the import never links it again and the player cannot claim it back by
     * themselves; the organiser still can in the sheet. Only that row: the player's other rows stay. A row the player
     * linked themselves, their own self-joined row or somebody else's participant is refused - the event page's
     * "Disconnect" / "Leave" are for those.
     */
    private function notMe(LeaveCompetition $message, string $participantId): bool
    {
        try {
            $participant = $this->participantRepository->get($participantId);
        } catch (CompetitionParticipantNotFound) {
            return false;
        }

        $player = $this->playerRepository->get($message->playerId);

        if (
            $participant->competition->id->toString() !== strtolower($message->competitionId)
            || $participant->isConnectedTo($player) === false
            || $participant->isDeleted()
            || $participant->source === ParticipantSource::SelfJoined
            || $participant->connectedVia?->isThePlayersOwn() === true
        ) {
            return false;
        }

        $this->participantLinks->unlink($participant, ParticipantLinkAction::NotMe, $player, $this->clock->now());

        // Nothing else links the player to the event any more = not going there either (as Leave)
        $otherRows = array_filter(
            $this->getCompetitionParticipants->getPlayerConnections($message->competitionId, $message->playerId),
            static fn (string $id): bool => $id !== $participant->id->toString(),
        );

        if ($otherRows === []) {
            $this->partnerListingRepository
                ->findOpenFor($message->competitionId, $message->playerId)
                ?->close(PartnerListingClosedReason::NotGoing, $this->clock->now());
        }

        return true;
    }
}
