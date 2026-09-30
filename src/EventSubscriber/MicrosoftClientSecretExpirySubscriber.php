<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Services\SocialLogin\MicrosoftClientSecretExpiryCheck;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Runs the Microsoft client-secret expiry reminder after the response is
 * sent (zero request latency). See MicrosoftClientSecretExpiryCheck.
 */
final readonly class MicrosoftClientSecretExpirySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MicrosoftClientSecretExpiryCheck $check,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        try {
            $this->check->check();
        } catch (\Throwable $exception) {
            // A reminder must never break a request
            $this->logger->info('Microsoft client secret expiry check failed.', [
                'exception' => $exception,
            ]);
        }
    }
}
