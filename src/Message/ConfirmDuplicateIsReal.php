<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\DuplicateResolvedVia;

readonly final class ConfirmDuplicateIsReal
{
    public function __construct(
        public string $caseId,
        public string $playerId,
        public DuplicateResolvedVia $via = DuplicateResolvedVia::ReviewPage,
    ) {
    }
}
