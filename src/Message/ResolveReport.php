<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\ReportStatus;

readonly final class ResolveReport
{
    public function __construct(
        public string $reportId,
        public string $adminId,
        public ReportStatus $status,
        public null|string $adminNote = null,
    ) {
    }
}
