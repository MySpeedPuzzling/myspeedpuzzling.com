<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Psr\Log\LoggerInterface;
use Sentry\State\HubInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class DebugTerminateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private HubInterface $hub,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['onTerminate', -50],
        ];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $transaction = $this->hub->getTransaction();

        $this->logger->warning('Sentry: kernel.terminate debug', [
            'method' => $request->getMethod(),
            'route' => $request->attributes->get('_route'),
            'uri' => $request->getRequestUri(),
            'has_transaction' => $transaction !== null,
            'transaction_name' => $transaction?->getName(),
            'transaction_sampled' => $transaction?->getSampled(),
        ]);
    }
}
