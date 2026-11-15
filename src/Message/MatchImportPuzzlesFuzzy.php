<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * The similar-name search for the next chunk of an import's puzzles nothing exact was found for
 * (docs/features/data-import/pairing.md "The similar-name search") - on the `imports` queue, a short job that sends
 * itself again while puzzles wait, so other players' imports take their turns in between. `pairingRun` is the run it
 * belongs to: once the import's puzzles were built again, the older chain stops.
 */
readonly final class MatchImportPuzzlesFuzzy implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $importId,
        public int $pairingRun,
    ) {
    }
}
