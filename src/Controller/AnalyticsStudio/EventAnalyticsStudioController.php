<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\AnalyticsStudio;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Exceptions\DraftNotVisible;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioPageBuilder;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The analytics studio of a one-time event's round (docs/features/competitions-management/analytics-studio.md §1.1) -
 * the commentators' data-free shell; assets/analytics_studio.js fills it from the JSON answers. Resolution as
 * EventStandingController: a standalone event holding the slug wins, an edition 301s to edition_analytics_studio, a
 * draft is DraftNotVisible off its team and a 404 for its team (AnalyticsStudioPageBuilder: never for an event that is
 * not public).
 */
final class EventAnalyticsStudioController extends AbstractController
{
    public function __construct(
        readonly private AnalyticsStudioPageBuilder $pageBuilder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/eventy/{slug}/analytics-studio/{roundSlug}',
            'en' => '/en/events/{slug}/analytics-studio/{roundSlug}',
            'es' => '/es/eventos/{slug}/analytics-studio/{roundSlug}',
            'ja' => '/ja/イベント/{slug}/analytics-studio/{roundSlug}',
            'fr' => '/fr/evenements/{slug}/analytics-studio/{roundSlug}',
            'de' => '/de/veranstaltungen/{slug}/analytics-studio/{roundSlug}',
        ],
        name: 'event_analytics_studio',
        methods: ['GET'],
    )]
    public function __invoke(
        // An edition's slug is unique only within its series - a standalone event holding the slug wins
        #[MapEntity(expr: 'repository.findOneBy({"slug": slug}, {"series": "DESC"})')] Competition $competition,
        string $roundSlug,
    ): Response {
        // A draft exists only for its team and admins - not even the redirect of an edition's studio, which would tell
        // the draft's series URL; its team gets the page builder's 404
        if ($competition->isHiddenAsDraft() && $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competition->id->toString()) === false) {
            throw new DraftNotVisible();
        }

        if ($competition->series !== null && $competition->series->slug !== null && $competition->slug !== null) {
            return $this->redirectToRoute('edition_analytics_studio', [
                'seriesSlug' => $competition->series->slug,
                'editionSlug' => $competition->slug,
                'roundSlug' => $roundSlug,
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        $page = $this->pageBuilder->build($competition->id->toString(), $roundSlug);

        return AnalyticsStudioPageBuilder::protect($this->render('analytics_studio/page.html.twig', [
            'page' => $page,
            'event_url' => $this->generateUrl('event_detail', ['slug' => $competition->slug]),
            'studio_route' => 'event_analytics_studio',
            'route_params' => ['slug' => $competition->slug],
        ]));
    }
}
