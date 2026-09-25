<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\MergeDecisionSource;

readonly final class RejectPuzzleMergeRequest
{
    public function __construct(
        public string $mergeRequestId,
        public string $reviewerId,
        public string $rejectionReason,
        public MergeDecisionSource $decisionSource = MergeDecisionSource::AdminUi,
    ) {
    }
}
