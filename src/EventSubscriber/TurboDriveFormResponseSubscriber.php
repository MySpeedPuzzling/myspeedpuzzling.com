<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turbo Drive refuses a 200 answer to a form submission: it only logs "Form responses must redirect to
 * another location" to the browser console, so the visitor clicks, sees the progress bar and nothing else -
 * no page, no flash, no field error. A controller must redirect on success and answer 422 when it renders
 * the form again (render() does that only when handed the FormInterface itself, never a FormView).
 *
 * Nothing on the server fails when that rule is broken, so this reports every such answer a real browser
 * receives - the claim-voucher form swallowed claims and errors this way until 2026-09-17.
 */
final readonly class TurboDriveFormResponseSubscriber implements EventSubscriberInterface
{
    private const string TURBO_STREAM_MEDIA_TYPE = 'text/vnd.turbo-stream.html';

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -1024],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || $request->isMethodSafe()) {
            return;
        }

        // Turbo adds its stream type to Accept on every form submission it drives; plain browser posts,
        // data-turbo="false" forms and Live Component actions never send it
        if (!str_contains((string) $request->headers->get('Accept'), self::TURBO_STREAM_MEDIA_TYPE)) {
            return;
        }

        // A submission inside a <turbo-frame> (e.g. the modal-frame) renders a 200 just fine
        if ($request->headers->has('Turbo-Frame')) {
            return;
        }

        $response = $event->getResponse();

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            return;
        }

        if (str_starts_with((string) $response->headers->get('Content-Type'), self::TURBO_STREAM_MEDIA_TYPE)) {
            return;
        }

        $this->logger->warning('Turbo Drive form submission answered with 200 - the browser discards it, the visitor sees nothing happen', [
            'route' => $request->attributes->get('_route'),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
        ]);
    }
}
