<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The organiser's save of one row of the participants page. It carries the change made in this edit, never the state
 * the row was opened with: the round entries ticked and unticked here, and the player connection only when this edit
 * changed it - an entry the results desk added meanwhile (advancing to a final, a table number) or a player who
 * connected themselves meanwhile stays. Takes turns with every other write to the event's participants.
 */
readonly final class EditCompetitionParticipant implements SerializedByLock
{
    /**
     * @param list<string> $addRoundIds rounds ticked in this edit - an entry that exists already stays as it is
     * @param list<string> $removeRoundIds rounds unticked in this edit - refused when the entry holds official results
     */
    public function __construct(
        // The event the organiser was authorised for - the participant must be one of its own
        public string $competitionId,
        public string $participantId,
        public string $name,
        public null|string $country,
        public null|string $externalId,
        // Connect/disconnect only when this edit changed the connection (null = disconnect)
        public bool $changePlayer = false,
        public null|string $playerId = null,
        public array $addRoundIds = [],
        public array $removeRoundIds = [],
        // The private note exists only on events that manage registration - any other edit keeps it as it is
        public bool $changeOrganizerNote = false,
        public null|string $organizerNote = null,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
