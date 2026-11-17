<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\AnalyticsStudio;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioRateLimit;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioRoundPredictionsBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The time predictions of every entrant of an analytics studio round (analytics-studio.md R28-R33) - `ready`, `warming`
 * or `none` (a pair/team round, nothing visible yet). Only what is cached is answered; when some are missing the
 * builder hands over a WarmAnalyticsStudioPredictions to send (at most once a minute per round and visible puzzle set).
 * `?v=` is the page's visible hash - a cache buster only, the visible puzzles are always read now. Stateless firewall,
 * rate-limited per IP (`analytics_studio_heavy`), `private, no-store`.
 */
final class AnalyticsStudioRoundPredictionsController extends AbstractController
{
    public function __construct(
        private readonly AnalyticsStudioRoundPredictionsBuilder $roundPredictionsBuilder,
        private readonly AnalyticsStudioRateLimit $rateLimit,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/analytics-studio/{roundId}/round-predictions',
        name: 'analytics_studio_round_predictions',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
        format: 'json',
    )]
    public function __invoke(Request $request, string $roundId): JsonResponse
    {
        $this->rateLimit->consume($request);

        $answer = $this->roundPredictionsBuilder->build($roundId);

        if ($answer->warm !== null) {
            try {
                $this->messageBus->dispatch($answer->warm);
                $this->roundPredictionsBuilder->markWarming($answer->warm);
            } catch (\Throwable $e) {
                // The answer stays `warming` - the page asks again, and so does its next request
                $this->logger->warning('Could not queue the analytics studio prediction warm', [
                    'round_id' => $roundId,
                    'exception' => $e,
                ]);
            }
        }

        $response = new JsonResponse($answer->json);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
