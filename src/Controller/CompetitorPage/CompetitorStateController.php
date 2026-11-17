<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitorPage;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Services\CompetitorPageLoader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A competitor page's state as JSON (CompetitorPageView, docs/features/competitions-management/competitor-pages.md
 * "competitor_state") - what the page's live part refetches on a stopwatch push or a reveal moment: the server's time,
 * the entrant's rounds with their stopwatch and Mercure topic (`/round-stopwatch/{roundId}`, public), puzzles by the
 * public reveal rule and the results the viewer may see. Nothing beyond what the page shows (no player ids). The same
 * 404s as the page; `private, no-store` for everybody (R-review-and-plan.md §2.6).
 */
final class CompetitorStateController extends AbstractController
{
    public function __construct(
        private readonly CompetitorPageLoader $competitorPageLoader,
        #[Autowire('%env(MERCURE_PUBLIC_URL)%')]
        private readonly string $mercurePublicUrl,
    ) {
    }

    #[Route(
        path: '/competitor-state/{competitionId}/{kind}/{id}',
        name: 'competitor_state',
        requirements: [
            'competitionId' => FirstTryConflictsController::ID_REQUIREMENT,
            'kind' => CompetitorPageLoader::KIND_REQUIREMENT,
            'id' => CompetitorPageLoader::ID_REQUIREMENT,
        ],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId, string $kind, string $id): JsonResponse
    {
        $view = $this->competitorPageLoader->load($competitionId, $kind, $id);

        $response = new JsonResponse([
            ...$view->jsonSerialize(),
            'mercure' => [
                'hubUrl' => $this->mercurePublicUrl,
                'topics' => $view->topics(),
            ],
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
