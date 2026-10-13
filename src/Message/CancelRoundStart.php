<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Cancels a scheduled start while it has not happened yet (docs/features/online-competitions/README.md §5.1).
 */
readonly final class CancelRoundStart
{
    public function __construct(
        public string $roundId,
        public string $actingPlayerId,
    ) {
    }
}
