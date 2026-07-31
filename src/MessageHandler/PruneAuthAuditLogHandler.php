<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\PruneAuthAuditLog;
use SpeedPuzzling\Web\Repository\AuthAuditEventRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * IP addresses are personal data - the retention window is the GDPR
 * justification (24 months matches the "investigate account issues" purpose).
 */
#[AsMessageHandler]
readonly final class PruneAuthAuditLogHandler
{
    public function __construct(
        private AuthAuditEventRepository $authAuditEventRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PruneAuthAuditLog $message): int
    {
        $before = $this->clock->now()->modify("-{$message->retentionMonths} months");

        return $this->authAuditEventRepository->deleteOlderThan($before);
    }
}
