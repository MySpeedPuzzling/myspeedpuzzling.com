<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitorPage;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Services\CompetitorPageLoader;
use SpeedPuzzling\Web\Services\CompetitorPageUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public competitor page a scanned competition tag leads to (docs/features/competitions-management/
 * competitor-pages.md, toolkit B-qr-competitor-page.md §4) - a person (p), a pair/team line-up (g), a table (t) or one
 * round's entry (e), with their rounds, puzzles and results as the viewer may see them. Only while the event has
 * competitor pages on. Never indexed and never cached: it depends on the viewer (their blocklist, their own entry).
 * The route name and parameters are CompetitorPageUrl's contract with the scan landing.
 */
final class CompetitorPageController extends AbstractController
{
    public function __construct(
        private readonly CompetitorPageLoader $competitorPageLoader,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/soutezici/{competitionId}/{kind}/{id}',
            'en' => '/en/competitor/{competitionId}/{kind}/{id}',
            'es' => '/es/competidor/{competitionId}/{kind}/{id}',
            'ja' => '/ja/competitor/{competitionId}/{kind}/{id}',
            'fr' => '/fr/concurrent/{competitionId}/{kind}/{id}',
            'de' => '/de/teilnehmer/{competitionId}/{kind}/{id}',
        ],
        name: CompetitorPageUrl::ROUTE,
        requirements: [
            'competitionId' => FirstTryConflictsController::ID_REQUIREMENT,
            'kind' => CompetitorPageLoader::KIND_REQUIREMENT,
            'id' => CompetitorPageLoader::ID_REQUIREMENT,
        ],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId, string $kind, string $id): Response
    {
        $view = $this->competitorPageLoader->load($competitionId, $kind, $id);

        $response = $this->render('competitor_page/page.html.twig', [
            'view' => $view,
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
