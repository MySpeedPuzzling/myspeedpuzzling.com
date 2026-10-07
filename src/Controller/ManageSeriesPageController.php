<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCompetitionPageSections;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
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
    )]
    public function __invoke(string $seriesId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $seriesId);

        $series = $this->getCompetitionSeries->byId($seriesId);

        return $this->render('manage_competition_page.html.twig', [
            'owner_type' => 'series',
            'owner_id' => $seriesId,
            'owner_name' => $series->name,
            'back_url' => $this->generateUrl('manage_competition_series', ['seriesId' => $seriesId]),
            'view_url' => $series->slug !== null ? $this->generateUrl('competition_series_detail', ['slug' => $series->slug]) : null,
            'entries' => $this->getCompetitionPageSections->forSeries($seriesId, includeHidden: true),
        ]);
    }
}
