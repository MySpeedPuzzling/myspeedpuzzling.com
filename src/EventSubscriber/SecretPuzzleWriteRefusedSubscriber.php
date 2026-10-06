<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Security\InternalApiAuthenticator;
use SpeedPuzzling\Web\Services\SecretPuzzleRefusalMessage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * An organiser trying to record something personal on a puzzle that is secret until its reveal (a time, a collection,
 * the wishlist, a listing, a loan - SecretPuzzleAccess::assertWritableBy()) gets the reason on the page instead of an
 * error page: a full page goes back where it came from with a flash, a Turbo Frame (the modals) shows the message in
 * the frame. The APIs keep their 409 (InternalApiErrorResponseSubscriber, API Platform).
 *
 * Runs before the error listener logs the exception: an expected refusal is no Sentry issue.
 */
final readonly class SecretPuzzleWriteRefusedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SecretPuzzleRefusalMessage $secretPuzzleRefusalMessage,
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 8],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $refusal = $event->getThrowable();

        if (!$refusal instanceof PuzzleNotRevealedYet) {
            return;
        }

        $request = $event->getRequest();

        if (self::isApiRequest($request)) {
            return;
        }

        $message = $this->secretPuzzleRefusalMessage->notRevealedYet($refusal);
        $frame = $request->headers->get('Turbo-Frame');

        if (is_string($frame) && $frame !== '') {
            $event->setResponse(new Response(
                $this->twig->render('secret_puzzle/_not_revealed_yet_frame.html.twig', [
                    'frame' => $frame,
                    'message' => $message,
                ]),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            ));

            return;
        }

        try {
            $session = $this->requestStack->getSession();
        } catch (SessionNotFoundException) {
            return;
        }

        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $session->getFlashBag()->add('warning', $message);
        $event->setResponse(new RedirectResponse($this->backUrl($request, $refusal), Response::HTTP_SEE_OTHER));
    }

    /**
     * The APIs answer the 409 themselves: matched by the route (API Platform's `_api_*`), or by the decoded path like
     * the firewalls match it (an encoded `/%61pi/` is still the API), or a JSON request.
     */
    public static function isApiRequest(Request $request): bool
    {
        $route = $request->attributes->get('_route');

        if (is_string($route) && str_starts_with($route, '_api_')) {
            return true;
        }

        $path = rawurldecode($request->getPathInfo());

        return str_starts_with($path, '/api/')
            || InternalApiAuthenticator::isInternalApiRequest($request)
            || $request->getPreferredFormat() === 'json';
    }

    private function backUrl(Request $request, PuzzleNotRevealedYet $refusal): string
    {
        $referer = $request->headers->get('referer');

        // Only back to a page of this site - never a redirect elsewhere
        if (is_string($referer) && str_starts_with($referer, $request->getSchemeAndHttpHost() . '/')) {
            return $referer;
        }

        return $this->urlGenerator->generate('puzzle_detail', ['puzzleId' => $refusal->puzzleId]);
    }
}
