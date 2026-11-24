<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The names of the table numbers of one or more rounds of an event (table-labels-and-plates.md) - the same list for
 * every round given, or null = back to plain numbers. All or nothing: `expected` is the list each round had when the
 * organiser opened the editor (null = plain numbers) - a round changed meanwhile, or one where somebody sits at a
 * number the new list does not name, refuses the whole message (TableLabelsRefused). Entries keep their numbers.
 */
readonly final class SetRoundTableLabels implements SerializedByLock
{
    /**
     * @param list<string> $roundIds
     * @param null|list<string> $labels
     * @param array<string, null|list<string>> $expected round id => the list the editor showed
     */
    public function __construct(
        public string $competitionId,
        public array $roundIds,
        public null|array $labels,
        public array $expected,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
