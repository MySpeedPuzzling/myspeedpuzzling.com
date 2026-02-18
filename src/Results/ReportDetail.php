<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ReportStatus;

readonly final class ReportDetail
{
    public function __construct(
        public string $reportId,
        public string $reporterName,
        public string $reporterCode,
        public string $reporterId,
        public string $reportedPlayerName,
        public string $reportedPlayerCode,
        public string $reportedPlayerId,
        public string $conversationId,
        public string $reason,
        public ReportStatus $status,
        public DateTimeImmutable $reportedAt,
        public null|string $puzzleName = null,
        public null|DateTimeImmutable $resolvedAt = null,
        public null|string $resolvedByName = null,
        public null|string $adminNote = null,
    ) {
    }
}
