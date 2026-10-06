<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Traefik's load balancer health check (lily.srv): may this container get new requests? A blue-green deploy drains
 * an old container before stopping it by creating the marker file in it (`docker exec <old> touch /tmp/drain`) -
 * Traefik then sends it nothing new while it finishes what it has.
 *
 * The path says "liveness" for history: Traefik's health check path lives in `traefik.*` labels, and changing a label
 * while old and new containers run side by side makes Traefik drop the service (all routes 404 until the old ones
 * are gone). Whether the container itself works - Docker's health check - is HealthCheckContainerController.
 */
final readonly class HealthCheckLivenessController
{
    public function __construct(
        private string $drainMarkerPath,
    ) {
    }

    #[Route(path: '/-/health-check/liveness')]
    public function __invoke(): Response
    {
        // Worker mode: PHP's stat cache would keep answering from before the marker appeared
        clearstatcache(true, $this->drainMarkerPath);

        if (file_exists($this->drainMarkerPath)) {
            return new JsonResponse(['status' => 'draining'], Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse(['status' => 'ok', 'time' => time()], headers: ['Cache-Control' => 'no-store']);
    }
}
