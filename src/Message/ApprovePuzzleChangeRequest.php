<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\MergeDecisionSource;

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
        public MergeDecisionSource $decisionSource = MergeDecisionSource::AdminUi,
        public null|string $decisionNote = null,
    ) {
    }
}
