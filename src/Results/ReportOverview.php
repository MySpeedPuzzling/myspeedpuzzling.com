<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ReportStatus;

readonly final class ReportOverview
{
    public function __construct(
        public string $reportId,
        public string $reporterName,
        public string $reporterCode,
        public string $reportedPlayerName,
        public string $reportedPlayerCode,
        public string $reportedPlayerId,
        public string $conversationId,
        public string $reason,
        public ReportStatus $status,
        public DateTimeImmutable $reportedAt,
        public null|string $puzzleName = null,
    ) {
    }
}
