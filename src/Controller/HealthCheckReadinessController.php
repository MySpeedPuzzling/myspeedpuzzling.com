<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Traefik's load balancer health check (lily.srv): may this container get new requests? A blue-green deploy drains
 * an old container before stopping it by creating the marker file in it (`docker exec <old> touch /tmp/drain`) -
 * Traefik then sends it nothing new while it finishes what it has. Liveness stays separate: the Docker health
 * check and the uptime probe ask whether the container works, and a draining container still does.
 */
final readonly class HealthCheckReadinessController
{
    public function __construct(
        private string $drainMarkerPath,
    ) {
    }

    #[Route(path: '/-/health-check/readiness')]
    public function __invoke(): Response
    {
        // Worker mode: PHP's stat cache would keep answering from before the marker appeared
        clearstatcache(true, $this->drainMarkerPath);
        $draining = file_exists($this->drainMarkerPath);

        return new JsonResponse(
            ['status' => $draining ? 'draining' : 'ready'],
            $draining ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }
}
