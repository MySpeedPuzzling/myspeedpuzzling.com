<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class DeleteCompetitionRound
{
    public function __construct(
        public string $roundId,
        // The internal API never deletes a round people have results in (their times would lose the round, official
        // results would be gone); the organiser's own delete button does - the times stay linked to the competition
        // either way, official results only after the organiser confirmed them (confirmedOfficialResultsHash)
        public bool $refuseWhenItHasResults = false,
        // Refuse (SecretPuzzlesWouldBeRevealed) when the delete reveals secret puzzles - the internal API without
        // "confirmReveal"; the organiser's page asks before it dispatches (SecretRevealPreview)
        public bool $refuseToReveal = false,
        // The organiser's yes, bound to exactly the list they were shown (SecretRevealPreview::hash() - also of an empty
        // list): re-checked after the locks, a different list now is refused (SecretPuzzlesWouldBeRevealed). Null = no
        // check (the caller asked differently)
        public null|string $confirmedRevealHash = null,
        // The organiser's yes to losing the round's official results, bound to exactly the entries they were shown
        // (OfficialResultsGuard::hashEntries() - also of an empty list): re-checked after the locks, a different list now
        // is refused (OfficialResultsChangedMeanwhile). Null = no check (refuseWhenItHasResults decides instead)
        public null|string $confirmedOfficialResultsHash = null,
    ) {
    }
}
