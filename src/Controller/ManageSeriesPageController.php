<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetCompetitionPageSections;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Value\PageSectionOwner;
use SpeedPuzzling\Web\Value\PageSectionType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page editor of a series: its sections show on the series page and on every edition's page, after the edition's own.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ManageSeriesPageController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionSeries $getCompetitionSeries,
        private readonly GetCompetitionPageSections $getCompetitionPageSections,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/upravit-stranku-serie/{seriesId}',
            'en' => '/en/manage-series-page/{seriesId}',
            'es' => '/es/manage-series-page/{seriesId}',
            'ja' => '/ja/manage-series-page/{seriesId}',
            'fr' => '/fr/manage-series-page/{seriesId}',
            'de' => '/de/manage-series-page/{seriesId}',
        ],
        name: 'manage_series_page',
        requirements: ['seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $seriesId): Response
    {
        $owner = PageSectionOwner::series($seriesId);
        $this->denyAccessUnlessGranted($owner->editAttribute(), $owner->id());

        $series = $this->getCompetitionSeries->byId($owner->id());

        $response = $this->render('manage_page_sections.html.twig', [
            'owner' => $owner,
            'owner_name' => $series->name,
            'back_url' => $this->generateUrl('manage_competition_series', ['seriesId' => $owner->id()]),
            'view_url' => $series->slug !== null ? $this->generateUrl('competition_series_detail', ['slug' => $series->slug]) : null,
            'sections' => $this->getCompetitionPageSections->forSeriesEditor($owner->id()),
            'section_types' => PageSectionType::availableFor($series->isOnline),
            'series_editor_url' => null,
            // The sections show on the public page only once the series is approved and published
            'publicly_visible' => $series->isPubliclyVisible(),
            // A draft says so instead of the approval wording (docs/features/organizations/README.md "Drafts")
            'is_draft' => $series->isDraft,
        ]);

        // An organiser's tool: never cached, never indexed (the template says noindex)
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
