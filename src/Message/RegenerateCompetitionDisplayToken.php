<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * A new link for the display - the old one stops working at once (D-displays-overlays.md §9.1).
 */
readonly final class RegenerateCompetitionDisplayToken
{
    public function __construct(
        public string $displayId,
    ) {
    }
}
