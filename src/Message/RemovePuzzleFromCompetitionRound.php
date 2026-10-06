<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RemovePuzzleFromCompetitionRound
{
    public function __construct(
        public string $roundPuzzleId,
        // The organiser's yes, bound to exactly the list they were shown (SecretRevealPreview::hash() - also of an empty
        // list): re-checked after the locks, a different list now is refused (SecretPuzzlesWouldBeRevealed). Null = no
        // check (the caller asked differently)
        public null|string $confirmedRevealHash = null,
    ) {
    }
}
