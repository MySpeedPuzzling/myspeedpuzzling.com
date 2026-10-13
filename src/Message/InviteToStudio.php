<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The host invites a participant to the round's studio (docs/features/online-competitions/contracts/phase-1b.md §4):
 * a new StudioInvite - the round's video must be on, the participant entered in the round, and no other invitation of
 * theirs open in it. The handler answers the new invitation (HandledStamp); the controller then tells the participant's
 * phone (StudioLiveUpdates::invited()).
 *
 * Under the event's participants lock: two hosts clicking "Invite" at once take turns, so only one invitation opens.
 */
readonly final class InviteToStudio implements SerializedByLock
{
    public function __construct(
        // The competition the caller authorised - a round or a participant of another one is refused
        public string $competitionId,
        public string $roundId,
        public string $participantId,
        public string $actingPlayerId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
