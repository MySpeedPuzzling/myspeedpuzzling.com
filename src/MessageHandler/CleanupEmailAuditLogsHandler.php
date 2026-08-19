<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\CleanupEmailAuditLogs;
use SpeedPuzzling\Web\Repository\EmailAuditLogRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class CleanupEmailAuditLogsHandler
{
    public function __construct(
        private EmailAuditLogRepository $emailAuditLogRepository,
        private ClockInterface $clock,
        private MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(CleanupEmailAuditLogs $message): int
    {
        $before = $this->clock->now()->modify("-{$message->retentionDays} days");

        $deleted = $this->emailAuditLogRepository->deleteOlderThan($before, $message->emailTypePrefix, $message->batchSize);

        // A full batch means more rows are waiting — continue in a fresh transaction.
        if ($deleted >= $message->batchSize) {
            $this->commandBus->dispatch(new CleanupEmailAuditLogs(
                retentionDays: $message->retentionDays,
                emailTypePrefix: $message->emailTypePrefix,
                batchSize: $message->batchSize,
            ));
        }

        return $deleted;
    }
}
