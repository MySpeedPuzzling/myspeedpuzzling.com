<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * Builds the distinct puzzles of an import from its parsed rows and pairs them with the catalogue by the exact
 * rules (docs/features/data-import/pairing.md "The flow"). Sent after every parse of a sheet, on the `imports`
 * queue; done twice, or again after another sheet, it ends the same - and never replaces a decision of the player.
 * The similar-name search follows in chunks (MatchImportPuzzlesFuzzy).
 */
readonly final class BuildImportPuzzles implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $importId,
    ) {
    }
}
