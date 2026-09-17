<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\RoundResults\RoundResultsPageBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EditionRoundResultsController extends AbstractController
{
    public function __construct(
        readonly private CompetitionRepository $competitionRepository,
        readonly private RoundResultsPageBuilder $roundResultsPageBuilder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/serie/{seriesSlug}/{editionSlug}/vysledky/{roundSlug}',
            'en' => '/en/series/{seriesSlug}/{editionSlug}/results/{roundSlug}',
            'es' => '/es/series/{seriesSlug}/{editionSlug}/resultados/{roundSlug}',
            'ja' => '/ja/series/{seriesSlug}/{editionSlug}/results/{roundSlug}',
            'fr' => '/fr/series/{seriesSlug}/{editionSlug}/resultats/{roundSlug}',
            'de' => '/de/series/{seriesSlug}/{editionSlug}/ergebnisse/{roundSlug}',
        ],
        name: 'edition_round_results',
    )]
    public function __invoke(string $seriesSlug, string $editionSlug, string $roundSlug): Response
    {
        $competition = $this->competitionRepository->getBySeriesAndEditionSlug($seriesSlug, $editionSlug);

        $page = $this->roundResultsPageBuilder->build($competition->id->toString(), $roundSlug);

        return $this->render('round_results.html.twig', [
            'page' => $page,
            'event_url' => $this->generateUrl('edition_detail', ['seriesSlug' => $seriesSlug, 'editionSlug' => $editionSlug]),
            'round_route' => 'edition_round_results',
            'round_route_params' => ['seriesSlug' => $seriesSlug, 'editionSlug' => $editionSlug],
        ]);
    }
}
