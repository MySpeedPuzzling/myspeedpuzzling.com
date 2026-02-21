<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class ApprovePuzzleChangeRequest
{
    /**
     * @param list<string> $selectedFields
     * @param array<string, string|int> $overrides
     */
    public function __construct(
        public string $changeRequestId,
        public string $reviewerId,
        public array $selectedFields = [],
        public array $overrides = [],
    ) {
    }
}
