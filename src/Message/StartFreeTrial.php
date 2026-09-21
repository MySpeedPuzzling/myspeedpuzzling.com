<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\FreeTrialSource;

readonly final class StartFreeTrial
{
    public function __construct(
        public string $playerId,
        public FreeTrialSource $source,
    ) {
    }
}
