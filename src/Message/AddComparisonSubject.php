<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class AddComparisonSubject
{
    public function __construct(
        public string $playerId,
        // ComparisonSubjectRef::toString() - "p-<uuid>" or "t-<uuid>"
        public string $subjectRef,
        // At the cap: the row (ComparisonSubject id) of the same line-up that makes room - the "swap"
        public null|string $replaceSubjectId = null,
    ) {
    }
}
