<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Log\LoggerInterface;
use Sentry\State\HubInterface;
use Sentry\Transport\ResultStatus;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Flushes Sentry client after each request.
 *
 * This is critical for FrankenPHP worker mode where PHP processes persist between requests.
 * Without explicit flush, Sentry events captured via Monolog (especially exceptions)
 * may not be sent because the PHP shutdown handlers never run in worker mode.
 *
 * This listener runs with low priority to ensure it executes AFTER:
 * - TracingRequestListener finishes the transaction
 * - Other terminate listeners complete their work
 */
#[AsEventListener(event: KernelEvents::TERMINATE, priority: -512)]
final readonly class SentryFlushListener
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $client = $this->hub->getClient();

        if ($client === null) {
            $this->logger->warning('Sentry client is not available - events may not be sent');

            return;
        }

        $result = $client->flush();
        $status = $result->getStatus();

        // Only log warning for non-success statuses (success and skipped are expected)
        if ((string) $status !== (string) ResultStatus::success() && (string) $status !== (string) ResultStatus::skipped()
        ) {
            $this->logger->warning('Sentry flush returned non-success status', [
                'status' => (string) $status,
            ]);
        }
    }
}
