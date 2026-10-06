<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * A round's puzzles become exactly this list: a puzzle no longer listed is removed, a new one attached (not hidden),
 * the others stay as they are (their "hide until the round starts" setting too). One transaction - the round invariant
 * (a puzzle in only one round per category of a competition) is checked before anything changes.
 */
readonly final class SetCompetitionRoundPuzzles
{
    /**
     * @param list<string> $puzzleIds distinct, lower case
     */
    public function __construct(
        public string $roundId,
        public array $puzzleIds,
    ) {
    }
}
