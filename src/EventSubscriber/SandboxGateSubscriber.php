<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Services\Sandbox\SandboxGate;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Answers every page of the sandbox with its welcome page until the visitor pressed "Enter" (SandboxGate). Runs
 * before routing, so even an unknown URL never shows a page of the site to a crawler. Nothing happens anywhere
 * SANDBOX is 0.
 */
final readonly class SandboxGateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SandboxMode $sandboxMode,
        private Environment $twig,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Before the router (32): a 404 page is a page of the site too
            KernelEvents::REQUEST => ['onKernelRequest', 40],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($this->sandboxMode->on === false || $event->isMainRequest() === false) {
            return;
        }

        $request = $event->getRequest();

        if (SandboxGate::hasEntered($request) || SandboxGate::isOpenPath($request->getPathInfo())) {
            return;
        }

        $response = new Response(
            $this->twig->render('sandbox/gate.html.twig', [
                'locale' => SandboxGate::locale($request),
                'return' => $request->isMethod(Request::METHOD_GET) ? $request->getRequestUri() : '/',
            ]),
            Response::HTTP_UNAUTHORIZED,
        );
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->setVary(['Cookie', 'Accept-Language']);

        $event->setResponse($response);
    }
}
