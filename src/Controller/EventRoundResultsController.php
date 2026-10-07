<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Services\RoundResults\RoundResultsPageBuilder;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EventRoundResultsController extends AbstractController
{
    public function __construct(
        readonly private RoundResultsPageBuilder $roundResultsPageBuilder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/eventy/{slug}/vysledky/{roundSlug}',
            'en' => '/en/events/{slug}/results/{roundSlug}',
            'es' => '/es/eventos/{slug}/resultados/{roundSlug}',
            'ja' => '/ja/イベント/{slug}/results/{roundSlug}',
            'fr' => '/fr/evenements/{slug}/resultats/{roundSlug}',
            'de' => '/de/veranstaltungen/{slug}/ergebnisse/{roundSlug}',
        ],
        name: 'event_round_results',
    )]
    public function __invoke(
        // An edition's slug is unique only within its series - a standalone event holding the slug wins
        #[MapEntity(expr: 'repository.findOneBy({"slug": slug}, {"series": "DESC"})')] Competition $competition,
        string $roundSlug,
    ): Response {
        // A series edition's slug is only unique within its series - its results live under the series URL
        if ($competition->series !== null && $competition->series->slug !== null && $competition->slug !== null) {
            return $this->redirectToRoute('edition_round_results', [
                'seriesSlug' => $competition->series->slug,
                'editionSlug' => $competition->slug,
                'roundSlug' => $roundSlug,
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        $page = $this->roundResultsPageBuilder->build($competition->id->toString(), $roundSlug, $competition->series?->name);

        return $this->render('round_results.html.twig', [
            'page' => $page,
            'event_url' => $this->generateUrl('event_detail', ['slug' => $competition->slug]),
            'round_route' => 'event_round_results',
            'round_route_params' => ['slug' => $competition->slug],
        ]);
    }
}
