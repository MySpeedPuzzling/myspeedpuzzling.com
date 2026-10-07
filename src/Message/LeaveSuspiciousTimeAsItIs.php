<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "Leave it as it is" on a result awaiting verification (docs/features/suspicious-time-review.md, "Where they see
 * it"): the result stays marked, the banner and the e-mail stop asking this person about it.
 */
readonly final class LeaveSuspiciousTimeAsItIs
{
    public function __construct(
        public string $caseId,
        public string $playerId,
    ) {
    }
}
