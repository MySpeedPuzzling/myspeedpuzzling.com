<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\DisplayFollowMode;

/**
 * Which round the display shows (D-displays-overlays.md §2 "Round"): the current round - among $roundIds only when
 * given (parallel halls; empty = every round) - or the pinned round $pinnedRoundId. Every round id must be one of the
 * display's event, otherwise the display is not found (never trust ids from the request).
 */
readonly final class ChangeCompetitionDisplayFollow
{
    /**
     * @param list<string> $roundIds
     */
    public function __construct(
        public string $displayId,
        public DisplayFollowMode $mode,
        public null|string $pinnedRoundId,
        public array $roundIds = [],
    ) {
    }
}
