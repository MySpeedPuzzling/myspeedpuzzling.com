<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetEventGoingCounts;
use SpeedPuzzling\Web\Query\GetEventOccurrences;
use SpeedPuzzling\Web\Query\GetEventSeriesDirectory;
use SpeedPuzzling\Web\Query\GetEventsViewerData;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageBuilder;
use SpeedPuzzling\Web\Services\EventsPage\LegacyEventsQueryRedirect;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\EventsScope;
use SpeedPuzzling\Web\Value\EventsView;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The events page (docs/features/events-page/README.md): an agenda of dated occurrences, the series directory and the
 * archive. A fixed number of statements: occurrences, series, going counts (+ the viewer's own relations when signed
 * in) - pinned by EventsPageQueryBudgetTest. Scope, view, month and search are rendered from the URL; the browser
 * changes them over the embedded index without a request.
 */
final class EventsController extends AbstractController
{
    private const int QUERY_MAX_LENGTH = 100;

    public function __construct(
        readonly private GetEventOccurrences $getEventOccurrences,
        readonly private GetEventSeriesDirectory $getEventSeriesDirectory,
        readonly private GetEventGoingCounts $getEventGoingCounts,
        readonly private GetEventsViewerData $getEventsViewerData,
        readonly private EventsPageBuilder $eventsPageBuilder,
        readonly private LegacyEventsQueryRedirect $legacyEventsQueryRedirect,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/eventy',
            'en' => '/en/events',
            'es' => '/es/eventos',
            'ja' => '/ja/イベント',
            'fr' => '/fr/evenements',
            'de' => '/de/veranstaltungen',
        ],
        name: 'events',
    )]
    public function __invoke(Request $request): Response
    {
        $redirect = $this->legacyEventsQueryRedirect->redirectFor($request);

        if ($redirect !== null) {
            return $redirect;
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $isAdmin = $profile?->isAdmin === true;
        $now = $this->clock->now();
        $today = OccurrenceDates::today($now);

        $occurrences = $this->getEventOccurrences->all($isAdmin);
        $series = $this->getEventSeriesDirectory->all($isAdmin);

        $comingIds = [];

        foreach ($occurrences as $occurrence) {
            $status = $occurrence->status($today);

            // Ongoing online events take registrations too: "N going", Full/waitlist
            if ($status->isComing() || $status === EventOccurrenceStatus::Ongoing) {
                $comingIds[] = $occurrence->competitionId;
            }
        }

        $goingCounts = $this->getEventGoingCounts->forCompetitions($comingIds);
        $viewer = $profile !== null ? $this->getEventsViewerData->forPlayer($profile->playerId) : null;

        $page = $this->eventsPageBuilder->build(
            occurrences: $occurrences,
            series: $series,
            goingCounts: $goingCounts,
            viewer: $viewer,
            scope: EventsScope::fromQuery($request->query->all()['country'] ?? null, $request->query->all()['onlineOnly'] ?? null),
            today: $now,
            locale: $request->getLocale(),
            homeCountry: $profile?->countryCode,
        );

        $view = EventsView::fromQuery($request->query->all()['view'] ?? null);
        $month = $request->query->all()['month'] ?? null;
        $query = $request->query->all()['q'] ?? null;

        return $this->render('events.html.twig', [
            'page' => $page,
            // The calendars' "today" - from the clock, like the page's statuses
            'today' => $today->format('Y-m-d'),
            'view' => $view,
            'month' => $view === EventsView::Calendar && is_string($month) && preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])$/', $month) === 1 ? $month : null,
            'query' => is_string($query) ? mb_substr(trim($query), 0, self::QUERY_MAX_LENGTH) : '',
        ]);
    }
}
