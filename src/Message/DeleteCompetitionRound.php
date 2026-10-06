<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class DeleteCompetitionRound
{
    public function __construct(
        public string $roundId,
        // The internal API never deletes a round people have results in (their times would lose the round); the
        // organiser's own delete button does - the times stay linked to the competition either way
        public bool $refuseWhenItHasResults = false,
        // Refuse (SecretPuzzlesWouldBeRevealed) when the delete reveals secret puzzles - the internal API without
        // "confirmReveal"; the organiser's page asks before it dispatches (SecretRevealPreview)
        public bool $refuseToReveal = false,
    ) {
    }
}
