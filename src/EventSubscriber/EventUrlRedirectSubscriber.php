<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Exceptions\DraftNotVisible;
use SpeedPuzzling\Web\Query\GetEventUrlRedirect;
use SpeedPuzzling\Web\Value\EventUrlPath;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * An old event URL whose page was moved by a restructuring tool (an edition moved to another series, a round to another
 * event, a series turned into an organization with a new slug) answers 301 to where the page is now
 * (docs/features/organizations/README.md, D6 - rows written by EventUrlRedirects, read by GetEventUrlRedirect).
 *
 * Runs on the 404 of the five event routes only, so a page that exists never pays for the lookup - **the live page
 * always wins**. A draft's own 404 (DraftNotVisible) is not redirected either: the draft holds the path, it is just not
 * public (P5). An explicit slug change through the "URL" field or the API writes no row - that rule is unchanged.
 */
readonly final class EventUrlRedirectSubscriber implements EventSubscriberInterface
{
    private const array ROUTES = [
        'event_detail',
        'competition_series_detail',
        'edition_detail',
        'event_round_results',
        'edition_round_results',
    ];

    public function __construct(
        private GetEventUrlRedirect $getEventUrlRedirect,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Before ErrorListener logs the 404 (priority 0), like ManufacturerSlugRedirectSubscriber
        return [KernelEvents::EXCEPTION => ['onKernelException', 8]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (
            $event->isMainRequest() === false
            || ($exception instanceof NotFoundHttpException) === false
            || $exception instanceof DraftNotVisible
        ) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_canonical_route') ?? $request->attributes->get('_route');
        $routeParams = $request->attributes->get('_route_params');

        if (in_array($route, self::ROUTES, true) === false || is_array($routeParams) === false) {
            return;
        }

        /** @var array<string, mixed> $routeParams */
        $path = self::path((string) $route, $routeParams);

        if ($path === null) {
            return;
        }

        $target = $this->getEventUrlRedirect->target($path);

        if ($target === null) {
            return;
        }

        $url = $this->urlGenerator->generate($target['route'], $target['params'] + ['_locale' => $request->getLocale()]);

        // The target is this very address (its own page answered the 404) - never a redirect loop
        if (rawurldecode($url) === rawurldecode($request->getBaseUrl() . $request->getPathInfo())) {
            return;
        }

        $queryString = $request->getQueryString();

        $event->setResponse(new RedirectResponse(
            $queryString !== null ? $url . '?' . $queryString : $url,
            Response::HTTP_MOVED_PERMANENTLY,
        ));
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function path(string $route, array $params): null|EventUrlPath
    {
        $slug = self::param($params, 'slug');
        $seriesSlug = self::param($params, 'seriesSlug');
        $editionSlug = self::param($params, 'editionSlug');
        $roundSlug = self::param($params, 'roundSlug');

        return match ($route) {
            'event_detail' => $slug !== null ? EventUrlPath::event($slug) : null,
            'competition_series_detail' => $slug !== null ? EventUrlPath::series($slug) : null,
            'edition_detail' => $seriesSlug !== null && $editionSlug !== null ? EventUrlPath::edition($seriesSlug, $editionSlug) : null,
            'event_round_results' => $slug !== null && $roundSlug !== null ? EventUrlPath::eventRound($slug, $roundSlug) : null,
            'edition_round_results' => $seriesSlug !== null && $editionSlug !== null && $roundSlug !== null
                ? EventUrlPath::editionRound($seriesSlug, $editionSlug, $roundSlug)
                : null,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function param(array $params, string $name): null|string
    {
        $value = $params[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
