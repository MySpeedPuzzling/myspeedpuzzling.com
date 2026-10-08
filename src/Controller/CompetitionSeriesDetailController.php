<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetCompetitionPageSections;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Query\GetEventGoingCounts;
use SpeedPuzzling\Web\Query\GetEventOccurrences;
use SpeedPuzzling\Web\Query\GetEventsViewerData;
use SpeedPuzzling\Web\Results\DraftState;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Results\EventsPage\ManageRef;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use SpeedPuzzling\Web\Services\EventDetail\SeriesPageBuilder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The series page (docs/features/events-page/detail-pages.md "Series page"): the series, its occurrences in one
 * statement, the going counts of the coming ones, and for a signed-in visitor their going/follow rows - SeriesPageBuilder
 * turns them into the page. An unapproved or rejected series is reachable at its URL (noindex, no star).
 */
final class CompetitionSeriesDetailController extends AbstractController
{
    public function __construct(
        readonly private GetCompetitionSeries $getCompetitionSeries,
        readonly private GetCompetitionPageSections $getCompetitionPageSections,
        readonly private GetEventOccurrences $getEventOccurrences,
        readonly private GetEventGoingCounts $getEventGoingCounts,
        readonly private GetEventsViewerData $getEventsViewerData,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private SeriesPageBuilder $seriesPageBuilder,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/serie/{slug}',
            'en' => '/en/series/{slug}',
            'es' => '/es/series/{slug}',
            'ja' => '/ja/series/{slug}',
            'fr' => '/fr/series/{slug}',
            'de' => '/de/series/{slug}',
        ],
        name: 'competition_series_detail',
    )]
    public function __invoke(string $slug, Request $request): Response
    {
        $series = $this->getCompetitionSeries->bySlug($slug);
        $now = $this->clock->now();

        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $viewer = $profile !== null ? $this->getEventsViewerData->forPlayer($profile->playerId) : null;
        // Its team (and admins) see its draft editions too, tagged (docs/features/organizations/README.md, P6)
        $canManage = $viewer !== null && (
            $this->isGranted('ADMIN_ACCESS')
            || $this->isGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $series->id)
        );
        $occurrences = $this->getEventOccurrences->forSeries($series->id, includeDrafts: $canManage);

        $page = $this->seriesPageBuilder->build(
            series: $series,
            occurrences: $occurrences,
            goingCounts: $this->getEventGoingCounts->forCompetitions(SeriesPageBuilder::comingCompetitionIds($occurrences, $now)),
            viewer: $viewer,
            now: $now,
            locale: $request->getLocale(),
        );

        return $this->render('competition_series_detail.html.twig', [
            'series' => $series,
            'page' => $page,
            // Organiser-written sections: queried only when one shows, and only on a public series - nothing an organiser
            // writes is published before the series is approved and published
            'page_sections' => $series->hasPageSections && $series->isPubliclyVisible()
                ? $this->getCompetitionPageSections->forSeriesPage($series->id)
                : [],
            'series_publicly_visible' => $series->isPubliclyVisible(),
            // The byline (docs/features/organizations/README.md): "Organized by", "Who can enter", "When it happens"
            'organization' => $series->organization,
            'eligibility' => $series->eligibility,
            'schedule' => $series->schedule,
            'draft_state' => $series->isDraft
                ? new DraftState(DraftState::KIND_SERIES, $series->id, $series->name, true)
                : null,
            'manage' => new ManageRef(ManageRef::KIND_SERIES, $series->id, $series->name),
            // The ⋯ menu's host: only for viewers with a ⋯ on the page - the series' organisers and admins, or an
            // organiser of one of its editions (their row ⋯)
            'show_menu' => $viewer !== null && (
                $canManage
                || array_intersect(
                    array_map(static fn (EventOccurrence $occurrence): string => strtolower($occurrence->competitionId), $occurrences),
                    $viewer->organizedCompetitionIds(),
                ) !== []
            ),
            'today' => OccurrenceDates::today($now),
        ]);
    }
}
