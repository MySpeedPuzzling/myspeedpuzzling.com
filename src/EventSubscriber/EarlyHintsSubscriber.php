<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Services\EarlyHintsLinkHeader;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends 103 Early Hints preloading the `app` entry's CSS and JS (FrankenPHP copies the header into the final
 * response too). The preloads must carry the same `integrity` as the tags: Chrome discards a preload without the
 * tag's SRI hash and fetches the file again - a double download of every new asset under the service worker.
 * `EarlyHintsLinkHeader` builds the header with the hashes.
 *
 * In production the 103 itself does not reach browsers (Traefik's retry middleware swallows 1xx responses), only
 * the copy of the header on the 200 does - see docs/performance-optimizations.md and docs/TODO.md.
 */
final readonly class EarlyHintsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EarlyHintsLinkHeader $earlyHintsLinkHeader,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 4096],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $pathInfo = $event->getRequest()->getPathInfo();

        if (str_starts_with($pathInfo, '/api/') || str_starts_with($pathInfo, '/oauth2/') || str_starts_with($pathInfo, '/webhook/')) {
            return;
        }

        $linkHeader = $this->earlyHintsLinkHeader->get();

        if ($linkHeader === null) {
            return;
        }

        $response = new Response();
        $response->headers->remove('Cache-Control');
        $response->headers->set('Link', $linkHeader);
        $response->sendHeaders(103);
    }
}
