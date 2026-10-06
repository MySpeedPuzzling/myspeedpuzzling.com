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
    ) {
    }
}
