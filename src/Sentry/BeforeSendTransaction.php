<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Sentry;

use Psr\Log\LoggerInterface;
use Sentry\Event;

final readonly class BeforeSendTransaction
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Event $event): Event
    {
        $contexts = $event->getContexts();
        $traceContext = $contexts['trace'] ?? null;

        $this->logger->warning('Sentry: transaction being sent', [
            'transaction' => $event->getTransaction(),
            'op' => $traceContext['op'] ?? 'unknown',
            'status' => $traceContext['status'] ?? 'unknown',
        ]);

        return $event;
    }
}
