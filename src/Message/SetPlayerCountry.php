<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "Add your country" (docs/features/players-page/README.md). The code is the raw form value - the handler validates it.
 */
readonly final class SetPlayerCountry
{
    public function __construct(
        public string $playerId,
        public string $countryCode,
    ) {
    }
}
