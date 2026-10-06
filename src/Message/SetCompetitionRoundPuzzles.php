<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * A round's puzzles become exactly this list: a puzzle no longer listed is removed, a new one attached (not hidden),
 * the others stay as they are (their secret reveal too). One transaction - the round invariant (a puzzle in only one
 * round per category of a competition) and the secrecy rules (a secret puzzle is never attached unhidden, a removal
 * reveals nothing unconfirmed) are checked before anything changes.
 */
readonly final class SetCompetitionRoundPuzzles
{
    /**
     * @param list<string> $puzzleIds distinct, lower case
     */
    public function __construct(
        public string $roundId,
        public array $puzzleIds,
        // Refuse (SecretPuzzlesWouldBeRevealed) when a removal reveals secret puzzles - the internal API without
        // "confirmReveal"
        public bool $refuseToReveal = false,
    ) {
    }
}
