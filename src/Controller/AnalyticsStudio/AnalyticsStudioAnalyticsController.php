<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\AnalyticsStudio;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioAnalyticsBuilder;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioRateLimit;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An analytics studio's analytics (analytics-studio.md §3.2): the visible puzzles' statistics and every entrant's
 * numbers and history. `?v=` (the state's visibleHash) only busts browser caches - the server always decides what is
 * visible itself. Stateless firewall, rate-limited per IP (`analytics_studio_heavy`), `private, max-age=30`.
 */
final class AnalyticsStudioAnalyticsController extends AbstractController
{
    public function __construct(
        private readonly AnalyticsStudioAnalyticsBuilder $analyticsBuilder,
        private readonly AnalyticsStudioRateLimit $rateLimit,
    ) {
    }

    #[Route(
        path: '/analytics-studio/{roundId}/analytics',
        name: 'analytics_studio_analytics',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
        format: 'json',
    )]
    public function __invoke(Request $request, string $roundId): JsonResponse
    {
        $this->rateLimit->consume($request);

        $response = new JsonResponse($this->analyticsBuilder->build($roundId));
        $response->setPrivate();
        $response->setMaxAge(30);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
