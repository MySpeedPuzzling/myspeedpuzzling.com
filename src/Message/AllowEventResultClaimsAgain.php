<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * An admin lifts a player's block from claiming spots of imported event results - set after their spots were removed by
 * other people twice (docs/features/event-results-import/README.md "Gates").
 */
readonly final class AllowEventResultClaimsAgain
{
    public function __construct(
        public string $playerId,
    ) {
    }
}
