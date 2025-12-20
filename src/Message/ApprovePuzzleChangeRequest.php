<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class ApprovePuzzleChangeRequest
{
    public function __construct(
        public string $changeRequestId,
        public string $reviewerId,
    ) {
    }
}
