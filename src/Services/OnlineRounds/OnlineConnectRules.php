<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Value\OnlineConnectStatus;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Whether the signed-in player who opened a personal link becomes its participant (docs/features/online-competitions/
 * README.md §5.4 "Sign-in from the link") - automatic only when it is unambiguous:
 * 1. the participant is this player already → AlreadyConnected;
 * 2. the participant is another account's → BelongsToOther (nothing changes; the link still works for competing);
 * 3. the player is another participant of this competition → IsOtherParticipant (never connected automatically -
 *    ConnectCompetitionParticipant would move their connection; the organiser sorts it out in the participants sheet);
 * 4. an organiser, maintainer or admin of the competition (COMPETITION_EDIT) previewing a participant's link →
 *    OrganiserConfirmRequired, unless they said "This is me" (`$confirm`);
 * 5. otherwise → Connected: the caller connects them (compete_connect dispatches ConnectCompetitionParticipant).
 * Decides for the signed-in viewer - the rights are theirs. Removed participants never get here (OnlineAccess).
 */
readonly final class OnlineConnectRules
{
    public function __construct(
        private GetCompetitionParticipants $getCompetitionParticipants,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function decide(CompetitionParticipant $participant, string $playerId, bool $confirm = false): OnlineConnectStatus
    {
        $connectedPlayer = $participant->player;

        if ($connectedPlayer !== null) {
            return $connectedPlayer->id->toString() === strtolower($playerId)
                ? OnlineConnectStatus::AlreadyConnected
                : OnlineConnectStatus::BelongsToOther;
        }

        $competitionId = $participant->competition->id->toString();
        $otherParticipants = array_diff(
            array_map(strtolower(...), $this->getCompetitionParticipants->getPlayerConnections($competitionId, $playerId)),
            [$participant->id->toString()],
        );

        if ($otherParticipants !== []) {
            return OnlineConnectStatus::IsOtherParticipant;
        }

        if ($confirm === false && $this->authorizationChecker->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId)) {
            return OnlineConnectStatus::OrganiserConfirmRequired;
        }

        return OnlineConnectStatus::Connected;
    }
}
