<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\DuplicateResolvedVia;

readonly final class ConfirmDuplicateIsReal
{
    /**
     * @param list<string> $copyTimeIds the copies of the set the page showed - empty = the set as it is now
     */
    public function __construct(
        public string $caseId,
        public string $playerId,
        public DuplicateResolvedVia $via = DuplicateResolvedVia::ReviewPage,
        public array $copyTimeIds = [],
    ) {
    }
}
