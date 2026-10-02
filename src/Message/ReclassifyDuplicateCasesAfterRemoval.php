<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * After the automatic removal of a case's copy: the open cases around the copy that stayed are classified again -
 * the removed copy may have been the one "in between" (docs/features/duplicate-results.md, "Automatic removal").
 * Handled result: the ids of the open Tier A cases afterwards.
 */
readonly final class ReclassifyDuplicateCasesAfterRemoval
{
    public function __construct(
        public string $caseId,
    ) {
    }
}
