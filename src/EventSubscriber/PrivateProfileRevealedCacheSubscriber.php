<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A response rendered for a viewer who is on somebody's allow list may name a private player, so
 * it must never reach a shared cache - whatever any other listener decided about it. Such a viewer
 * is always signed in, so this is a second lock on a door that is already shut
 * (docs/features/private-profile-allow-list.md).
 */
readonly final class PrivateProfileRevealedCacheSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After AnonymousCacheHeadersSubscriber (-900), which is the one that makes responses public
        return [
            KernelEvents::RESPONSE => ['onResponse', -910],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($this->privateProfileAccess->hasRevealedSomebody() === false) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->remove(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER);
        $response->headers->set('Cache-Control', 'private, no-store');
    }
}
