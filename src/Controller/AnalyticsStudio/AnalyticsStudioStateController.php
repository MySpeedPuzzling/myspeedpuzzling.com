<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\AnalyticsStudio;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioStateBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An analytics studio's state now (docs/features/competitions-management/analytics-studio.md §3.1): the round, its
 * stopwatch, the puzzle slots by the public reveal rule, the entrants and the results the public may see. On the
 * stateless firewall - no session, no viewer, the same answer for everybody; micro-cached 2 s server-side
 * (AnalyticsStudioStateBuilder), never in a browser. 404 when the round's event is not public or the round has no slug.
 */
final class AnalyticsStudioStateController extends AbstractController
{
    public function __construct(
        private readonly AnalyticsStudioStateBuilder $stateBuilder,
    ) {
    }

    #[Route(
        path: '/analytics-studio/{roundId}/state',
        name: 'analytics_studio_state',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
        format: 'json',
    )]
    public function __invoke(string $roundId): JsonResponse
    {
        $response = new JsonResponse($this->stateBuilder->build($roundId));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
