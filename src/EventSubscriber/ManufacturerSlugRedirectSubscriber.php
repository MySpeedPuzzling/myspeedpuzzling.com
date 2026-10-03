<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Query\GetManufacturerSlugRedirect;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A brand page whose slug belonged to a brand that was merged away answers 301 to
 * the same page of the surviving brand (docs/features/brand-duplicates.md).
 *
 * Runs on the 404 only, so a brand page that exists never pays for the lookup.
 */
readonly final class ManufacturerSlugRedirectSubscriber implements EventSubscriberInterface
{
    private const array BRAND_ROUTES = [
        'brand_puzzles',
        'brand_puzzles_page',
        'brand_pieces_puzzles',
        'brand_pieces_puzzles_page',
        'brand_hardest_puzzles',
        'brand_easiest_puzzles',
    ];

    public function __construct(
        private GetManufacturerSlugRedirect $getManufacturerSlugRedirect,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Before ErrorListener logs the 404 (priority 0)
        return [KernelEvents::EXCEPTION => ['onKernelException', 8]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if ($event->isMainRequest() === false || ($event->getThrowable() instanceof NotFoundHttpException) === false) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_canonical_route') ?? $request->attributes->get('_route');
        $routeParams = $request->attributes->get('_route_params');

        if (
            in_array($route, self::BRAND_ROUTES, true) === false
            || is_array($routeParams) === false
            || is_string($routeParams['slug'] ?? null) === false
        ) {
            return;
        }

        $targetSlug = $this->getManufacturerSlugRedirect->targetSlug($routeParams['slug']);

        if ($targetSlug === null) {
            return;
        }

        $url = $this->urlGenerator->generate($route, ['slug' => $targetSlug] + $routeParams);
        $queryString = $request->getQueryString();

        $event->setResponse(new RedirectResponse(
            $queryString !== null ? $url . '?' . $queryString : $url,
            Response::HTTP_MOVED_PERMANENTLY,
        ));
    }
}
