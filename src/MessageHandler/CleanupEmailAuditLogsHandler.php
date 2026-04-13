<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\CleanupEmailAuditLogs;
use SpeedPuzzling\Web\Repository\EmailAuditLogRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CleanupEmailAuditLogsHandler
{
    public function __construct(
        private EmailAuditLogRepository $emailAuditLogRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CleanupEmailAuditLogs $message): int
    {
        $before = $this->clock->now()->modify("-{$message->retentionDays} days");

        return $this->emailAuditLogRepository->deleteOlderThan($before);
    }
}
