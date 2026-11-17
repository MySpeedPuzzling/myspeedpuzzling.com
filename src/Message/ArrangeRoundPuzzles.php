<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The organiser's order and labels of a round's puzzles (competition toolkit A2) - what judges and competitors pick by
 * before the reveal. Takes turns with every other change of the event's rounds and entries (CompetitionParticipantsLock,
 * like EditCompetitionRound).
 */
readonly final class ArrangeRoundPuzzles implements SerializedByLock
{
    /**
     * @param list<string> $roundPuzzleIds the round's puzzles in the new order; a puzzle of the round left out keeps its
     *     place after them (one added meanwhile), an id of another round is ignored
     * @param array<string, null|string> $labels by round puzzle id - empty or null = the letter of its place; a puzzle left
     *     out keeps its label
     */
    public function __construct(
        public string $roundId,
        // The round's event, as the caller authorised it - the handler refuses a round of another event
        public string $competitionId,
        public array $roundPuzzleIds,
        public array $labels,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
