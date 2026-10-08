<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventsPage;

use SpeedPuzzling\Web\Query\GetCompetitionSlugsForSitemap;
use SpeedPuzzling\Web\Value\EventsScope;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The old events page's query parameters, redirected to the new page's state
 * (docs/features/events-page/README.md, "URL parameters and redirects"):
 *
 * - `?timePeriod=past` without a scope → `events_archive` of the newest year with a past public occurrence (302: the
 *   newest year changes, a cached 301 would keep sending people to an old year);
 * - `?country=cz&timePeriod=past` (or `onlineOnly`) → the same URL without `timePeriod` (the scope view shows its past);
 * - any other `timePeriod` value is dropped;
 * - `?showCalendar=1` → `?view=calendar`, other `showCalendar` values are dropped;
 * - every other parameter is kept; these redirects are 301, the new URL means the same forever.
 *
 * Null = nothing to redirect, the page renders. The archive year costs one statement, only on that redirect.
 */
readonly final class LegacyEventsQueryRedirect
{
    private const string TIME_PERIOD = 'timePeriod';
    private const string SHOW_CALENDAR = 'showCalendar';

    public function __construct(
        private GetCompetitionSlugsForSitemap $getCompetitionSlugsForSitemap,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function redirectFor(Request $request): null|RedirectResponse
    {
        $query = $request->query->all();

        if (array_key_exists(self::TIME_PERIOD, $query) === false && array_key_exists(self::SHOW_CALENDAR, $query) === false) {
            return null;
        }

        $timePeriod = $query[self::TIME_PERIOD] ?? null;
        $showCalendar = $query[self::SHOW_CALENDAR] ?? null;
        unset($query[self::TIME_PERIOD], $query[self::SHOW_CALENDAR]);

        if ($timePeriod === 'past') {
            $scope = EventsScope::fromQuery($query['country'] ?? null, $query['onlineOnly'] ?? null);

            if ($scope->isEverywhere()) {
                $newestYear = $this->getCompetitionSlugsForSitemap->archiveYears()[0] ?? null;

                if ($newestYear !== null) {
                    return new RedirectResponse(
                        $this->urlGenerator->generate('events_archive', ['year' => $newestYear]),
                        Response::HTTP_FOUND,
                    );
                }
            }
        }

        if ($showCalendar === '1' || $showCalendar === 'true') {
            $query['view'] = 'calendar';
        }

        $url = $request->getBaseUrl() . $request->getPathInfo();

        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return new RedirectResponse($url, Response::HTTP_MOVED_PERMANENTLY);
    }
}
