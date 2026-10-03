<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\ComparisonView;

readonly final class ChangeComparisonView
{
    public function __construct(
        public string $playerId,
        public ComparisonView $view,
    ) {
    }
}
