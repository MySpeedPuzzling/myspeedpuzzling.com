<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class EarlyHintsSubscriber implements EventSubscriberInterface
{
    private null|string $linkHeader = null;

    public function __construct(
        private readonly string $entrypointsPath,
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

        $linkHeader = $this->buildLinkHeader();

        if ($linkHeader === null) {
            return;
        }

        $response = new Response();
        $response->headers->remove('Cache-Control');
        $response->headers->set('Link', $linkHeader);
        $response->sendHeaders(103);
    }

    private function buildLinkHeader(): null|string
    {
        if ($this->linkHeader !== null) {
            return $this->linkHeader;
        }

        $content = @file_get_contents($this->entrypointsPath);

        if ($content === false) {
            return null;
        }

        /** @var array{entrypoints: array{app?: array{css?: list<string>, js?: list<string>}}} $data */
        $data = json_decode($content, true);
        $entrypoints = $data['entrypoints']['app'] ?? [];

        $links = [];

        foreach ($entrypoints['css'] ?? [] as $file) {
            $links[] = "<{$file}>; rel=preload; as=style";
        }

        foreach ($entrypoints['js'] ?? [] as $file) {
            $links[] = "<{$file}>; rel=preload; as=script";
        }

        if ($links === []) {
            return null;
        }

        $this->linkHeader = implode(', ', $links);

        return $this->linkHeader;
    }
}
