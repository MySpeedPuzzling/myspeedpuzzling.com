<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\DuplicateResolvedVia;

/**
 * "Keep this one" on a set of copies (docs/features/duplicate-results.md, "Review page") - the handler answers
 * with Results\DuplicateCopiesDeleted.
 */
readonly final class KeepDuplicateCopy
{
    /**
     * @param list<string> $copyTimeIds the copies of the set the page showed - empty = the set as it is now
     */
    public function __construct(
        public string $caseId,
        public string $keepTimeId,
        public string $playerId,
        public DuplicateResolvedVia $via = DuplicateResolvedVia::ReviewPage,
        public array $copyTimeIds = [],
    ) {
    }
}
