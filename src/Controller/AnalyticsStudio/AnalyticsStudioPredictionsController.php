<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\AnalyticsStudio;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioPredictionsBuilder;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioRateLimit;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The time predictions of an analytics studio's compare set (analytics-studio.md R3): `?entries=a,b,c,d` (at most 4
 * entry ids; others and unknown ones are ignored). Stateless firewall, rate-limited per IP (`analytics_studio_heavy`),
 * `private, max-age=30`.
 */
final class AnalyticsStudioPredictionsController extends AbstractController
{
    public function __construct(
        private readonly AnalyticsStudioPredictionsBuilder $predictionsBuilder,
        private readonly AnalyticsStudioRateLimit $rateLimit,
    ) {
    }

    #[Route(
        path: '/analytics-studio/{roundId}/predictions',
        name: 'analytics_studio_predictions',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
        format: 'json',
    )]
    public function __invoke(Request $request, string $roundId): JsonResponse
    {
        $this->rateLimit->consume($request);

        $entries = array_values(array_filter(
            explode(',', $request->query->getString('entries')),
            static fn (string $entry): bool => preg_match('/^[0-9a-fA-F-]{36}$/', $entry) === 1,
        ));

        $response = new JsonResponse($this->predictionsBuilder->build($roundId, $entries));
        $response->setPrivate();
        $response->setMaxAge(30);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
