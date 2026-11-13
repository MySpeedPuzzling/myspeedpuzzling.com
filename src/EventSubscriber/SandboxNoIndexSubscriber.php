<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * On the sandbox every answer of the app - pages, JSON, images it serves itself, errors - tells search engines to keep
 * out (docs/features/sandbox/README.md "Search engines"). The edge in front of it (lily.srv) adds the same header and a
 * password; this one holds even if the edge is ever configured wrong.
 */
final readonly class SandboxNoIndexSubscriber implements EventSubscriberInterface
{
    public const string HEADER_VALUE = 'noindex, nofollow, noarchive, nosnippet, noimageindex';

    public function __construct(
        private SandboxMode $sandboxMode,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Low priority: after every other listener that may set its own X-Robots-Tag (ours wins)
            KernelEvents::RESPONSE => ['onKernelResponse', -1024],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if ($this->sandboxMode->on === false) {
            return;
        }

        $event->getResponse()->headers->set('X-Robots-Tag', self::HEADER_VALUE);
    }
}
