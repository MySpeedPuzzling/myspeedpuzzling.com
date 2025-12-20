<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RejectPuzzleChangeRequest
{
    public function __construct(
        public string $changeRequestId,
        public string $reviewerId,
        public string $rejectionReason,
    ) {
    }
}
