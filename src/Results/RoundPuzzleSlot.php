<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One puzzle of a round as a place in the round (RoundPuzzlesVisibleNow, competition toolkit A2): its neutral label and
 * position - what judges and competitors pick by before the reveal - and the puzzle itself only once every viewer may
 * see it (the public rule, R-review-and-plan.md §1.5). Nothing of a hidden puzzle is in here: no name, picture, puzzle
 * id or piece count.
 */
readonly final class RoundPuzzleSlot
{
    public function __construct(
        // competition_round_puzzle.id - what a pick round's records refer to, never the catalogue puzzle id
        public string $roundPuzzleId,
        // 1..N in the organiser's order (RoundPuzzleLabel)
        public int $position,
        // The organiser's label or the letter of the position (RoundPuzzleLabel::effective())
        public string $label,
        // When its name comes out - null when out already, or when no moment is known yet (a manual reveal, a stopwatch
        // reveal before the start)
        public null|DateTimeImmutable $revealsAt,
        // Null while its name is hidden from everybody
        public null|VisibleRoundPuzzle $puzzle,
    ) {
    }

    public function isHidden(): bool
    {
        return $this->puzzle === null;
    }
}
