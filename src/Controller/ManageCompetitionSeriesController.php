<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ManageCompetitionSeriesController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionSeries $getCompetitionSeries,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/sprava-serie/{seriesId}',
            'en' => '/en/manage-series/{seriesId}',
            'es' => '/es/manage-series/{seriesId}',
            'ja' => '/ja/manage-series/{seriesId}',
            'fr' => '/fr/manage-series/{seriesId}',
            'de' => '/de/manage-series/{seriesId}',
        ],
        name: 'manage_competition_series',
    )]
    public function __invoke(string $seriesId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $seriesId);

        $series = $this->getCompetitionSeries->byId($seriesId);
        $upcomingEditions = $this->getCompetitionSeries->upcomingEditions($seriesId);
        $pastEditions = $this->getCompetitionSeries->pastEditions($seriesId);

        return $this->render('manage_competition_series.html.twig', [
            'series' => $series,
            'upcoming_editions' => $upcomingEditions,
            'past_editions' => $pastEditions,
        ]);
    }
}
