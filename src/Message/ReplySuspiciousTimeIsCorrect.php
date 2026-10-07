<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "The time is correct" on a result awaiting verification (docs/features/suspicious-time-review.md, "Where they see
 * it"), with an optional message for the moderators - once per mark. Answers true when the reply was recorded, false
 * when this person had sent it for this mark already.
 */
readonly final class ReplySuspiciousTimeIsCorrect
{
    public const int MAX_TEXT_LENGTH = 500;

    public function __construct(
        public string $caseId,
        public string $playerId,
        public null|string $text = null,
    ) {
    }
}
