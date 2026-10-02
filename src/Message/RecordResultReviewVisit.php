<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RecordResultReviewVisit
{
    public function __construct(
        public string $contactId,
        public string $playerId,
    ) {
    }
}
