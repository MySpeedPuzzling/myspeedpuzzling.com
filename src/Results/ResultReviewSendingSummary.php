<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class ResultReviewSendingSummary
{
    public function __construct(
        public int $sent,
        public int $skipped,
        public int $stillPlanned,
        public int $sentTodayBefore,
    ) {
    }
}
