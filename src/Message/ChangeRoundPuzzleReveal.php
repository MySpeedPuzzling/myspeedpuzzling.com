<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

readonly final class ChangeRoundPuzzleReveal
{
    public function __construct(
        public string $roundPuzzleId,
        public PuzzleHideMode $hideMode,
        public RoundPuzzleReveal $revealMode,
        // The instant of a scheduled reveal, null otherwise
        public null|DateTimeImmutable $scheduledAt = null,
        // Yes to publishing the name now: "entirely" -> "image only" of a row still hiding it (it is never hidden again)
        public bool $namePublicationConfirmed = false,
        // The round's automatic reveal (start + reveal delay) the caller agreed to - required for an automatic reveal:
        // checked under the handler's lock, another moment now (the round changed after the organiser's page was
        // loaded) or none at all is refused (AutomaticRevealChangedMeanwhile). No caller is exempt - one that offers
        // "Automatic" says which moment it showed (CompetitionRound::automaticRevealAt() as it read the round). Ignored
        // for scheduled and manual reveals.
        public null|DateTimeImmutable $shownAutomaticRevealAt = null,
    ) {
    }
}
