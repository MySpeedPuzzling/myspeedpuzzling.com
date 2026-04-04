<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CompetitionSeriesDetailController extends AbstractController
{
    public function __construct(
        readonly private GetCompetitionSeries $getCompetitionSeries,
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
    public function __invoke(
        #[MapEntity(mapping: ['slug' => 'slug'])] CompetitionSeries $series,
    ): Response {
        $seriesOverview = $this->getCompetitionSeries->byId($series->id->toString());
        $upcomingEditions = $this->getCompetitionSeries->upcomingEditions($series->id->toString());
        $pastEditions = $this->getCompetitionSeries->pastEditions($series->id->toString());

        return $this->render('competition_series_detail.html.twig', [
            'series' => $seriesOverview,
            'upcoming_editions' => $upcomingEditions,
            'past_editions' => $pastEditions,
        ]);
    }
}
