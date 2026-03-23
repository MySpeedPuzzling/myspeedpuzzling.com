<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class ReportFeatureRequestComment
{
    public function __construct(
        public string $reporterId,
        public string $commentId,
    ) {
    }
}
