<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Services\MercureTopicCollector;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Authorization;

final class MercureSubscribeCookieListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly Authorization $authorization,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MercureTopicCollector $topicCollector,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // Must run BEFORE Symfony's SetCookieSubscriber (priority 0)
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 10],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return;
        }

        $playerId = $profile->playerId;
        $topics = $this->topicCollector->getAllTopicsForPlayer($playerId);

        try {
            $this->authorization->setCookie($event->getRequest(), $topics);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to set Mercure subscribe cookie', [
                'exception' => $e,
            ]);
        }
    }
}
