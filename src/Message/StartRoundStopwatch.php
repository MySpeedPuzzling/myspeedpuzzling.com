<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "Start" - now, or resume a stopped stopwatch (in person). With $revealPuzzles the round's puzzles waiting for the
 * stopwatch start (RoundPuzzleReveal::Stopwatch) are revealed at that moment - only for exactly the list the organiser
 * confirmed ($revealListHash = SecretRevealPreview::stopwatchStartHash()); unticked (the default) nothing is revealed
 * (docs/features/competitions-management/toolkit/A-round-formats.md §4).
 */
readonly final class StartRoundStopwatch
{
    public function __construct(
        public string $roundId,
        public bool $revealPuzzles = false,
        public null|string $revealListHash = null,
    ) {
    }
}
