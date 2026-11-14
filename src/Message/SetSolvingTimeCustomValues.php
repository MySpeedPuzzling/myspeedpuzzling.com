<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * A player's own values on one result (docs/features/custom-fields.md) - their own row, whoever else is on the
 * result. Never part of EditPuzzleSolvingTime: an edit of a pair's result tells the whole team and recalculates
 * everybody's achievements, and a private field is nobody else's business.
 *
 * Only the fields given change: a value as it was typed (CustomValues::normalise() reads it), null or an empty text
 * takes the field's value away, a field left out keeps what it has. Sent twice it stores the same - the add form
 * relies on that.
 */
readonly final class SetSolvingTimeCustomValues
{
    /**
     * @param array<string, mixed> $values keyed by custom field id
     */
    public function __construct(
        public string $solvingTimeId,
        public string $playerId,
        public array $values,
    ) {
    }
}
