<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What of one round's puzzles every viewer may see at one moment (RoundPuzzlesVisibleNow). Nothing about a puzzle
 * still kept secret is in here - not its name, picture or even that it exists; only the moment the next one comes out.
 */
readonly final class RoundPuzzlesNow
{
    /**
     * @param list<VisibleRoundPuzzle> $puzzles ordered by name
     * @param null|int $nextRevealAtMs the earliest moment (Unix milliseconds) after now at which something of the round's
     *     puzzles comes out (a reveal of the round, a puzzle's own hide_until / hide_image_until) - null when nothing is
     *     scheduled (all out, or only manual reveals left). A moment, never a puzzle: a page refetches then
     */
    public function __construct(
        public array $puzzles,
        public null|int $nextRevealAtMs,
    ) {
    }

    /**
     * At least one puzzle of the round can be seen (by name at least) - a display's "puzzle revealed" condition
     */
    public function anyRevealed(): bool
    {
        return $this->puzzles !== [];
    }
}
