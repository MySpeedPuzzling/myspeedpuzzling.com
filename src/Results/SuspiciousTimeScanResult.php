<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Services\SuspiciousTimes\SuspiciousTimeScan: the detection and the notice run, each null when it failed (logged).
 */
readonly final class SuspiciousTimeScanResult
{
    public function __construct(
        public null|SuspiciousTimeScanSummary $detection,
        // Notices created - null for a dry run or when the notice run failed
        public null|int $notices,
        public bool $noticesFailed = false,
    ) {
    }
}
