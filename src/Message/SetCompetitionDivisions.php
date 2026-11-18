<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\DivisionInput;

/**
 * The event's divisions and which of them each round uses - the whole picture in one write (the divisions page and
 * `PUT /internal-api/competitions/{id}/divisions` both save it; shared-puzzles-and-divisions.md §3.3, D1).
 *
 * `divisions` is the complete list in its order (an event division left out is deleted); `rounds` maps a round id to
 * the ids of the divisions it uses (a round left out uses none). Taking a division from entries that carry it - the
 * division removed, or removed from their round - is refused (DivisionsInUse) unless `confirmClearing`. Under the event's
 * participants lock: entries are written by it, and a round being deleted or moved meanwhile never gets a subset.
 *
 * The handler answers the ids of the rounds whose divisions or entries changed (their open desks are refreshed).
 */
readonly final class SetCompetitionDivisions implements SerializedByLock
{
    /**
     * @param list<DivisionInput> $divisions
     * @param array<string, list<string>> $rounds round id => division ids
     */
    public function __construct(
        public string $competitionId,
        public array $divisions,
        public array $rounds,
        public bool $confirmClearing = false,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
