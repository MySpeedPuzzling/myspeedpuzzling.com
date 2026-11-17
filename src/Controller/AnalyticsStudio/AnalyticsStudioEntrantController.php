<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\AnalyticsStudio;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioEntrantBuilder;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioRateLimit;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One entrant of an analytics studio in detail (analytics-studio.md §3.3): the analytics row plus every attempt, the
 * latest similar solves, recent form, predictions and the earlier published rounds at this event. 404 for an entry not
 * of this round. Stateless firewall, rate-limited per IP (`analytics_studio_heavy`), `private, max-age=30`.
 */
final class AnalyticsStudioEntrantController extends AbstractController
{
    public function __construct(
        private readonly AnalyticsStudioEntrantBuilder $entrantBuilder,
        private readonly AnalyticsStudioRateLimit $rateLimit,
    ) {
    }

    #[Route(
        path: '/analytics-studio/{roundId}/entrant/{entryId}',
        name: 'analytics_studio_entrant',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT, 'entryId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
        format: 'json',
    )]
    public function __invoke(Request $request, string $roundId, string $entryId): JsonResponse
    {
        $this->rateLimit->consume($request);

        $response = new JsonResponse($this->entrantBuilder->build($roundId, $entryId));
        $response->setPrivate();
        $response->setMaxAge(30);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
