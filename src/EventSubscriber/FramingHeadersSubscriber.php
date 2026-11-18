<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Clickjacking guard (real events L3): no page of the site may be framed by another site - every answer gets
 * `X-Frame-Options: SAMEORIGIN` (our own pages may still frame each other, e.g. the e-mail audit's preview) - except an
 * answer that states its own framing rule: a `Content-Security-Policy` with `frame-ancestors` (which browsers prefer
 * over X-Frame-Options anyway) or its own X-Frame-Options. Today that is the embeddable standing alone
 * (StandingEmbedController, `frame-ancestors *`), meant for an `<iframe>` on an organiser's website.
 */
final readonly class FramingHeadersSubscriber implements EventSubscriberInterface
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Late: after the controllers and the other response listeners
            KernelEvents::RESPONSE => ['onKernelResponse', -256],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() === false) {
            return;
        }

        $headers = $event->getResponse()->headers;

        if ($headers->has('X-Frame-Options')) {
            return;
        }

        if (str_contains(strtolower((string) $headers->get('Content-Security-Policy')), 'frame-ancestors')) {
            return;
        }

        $headers->set('X-Frame-Options', 'SAMEORIGIN');
    }
}
