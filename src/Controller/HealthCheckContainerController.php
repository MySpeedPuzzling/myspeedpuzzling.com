<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Docker's health check (lily.srv compose): does this container serve PHP? Ignores the blue-green drain on purpose -
 * a draining container still works, and the deploy waits for new containers to turn healthy here. Traefik's
 * "may it get requests" check is HealthCheckLivenessController.
 */
final readonly class HealthCheckContainerController
{
    #[Route(path: '/-/health-check/container')]
    public function __invoke(): Response
    {
        return new JsonResponse(['status' => 'ok', 'time' => time()], headers: ['Cache-Control' => 'no-store']);
    }
}
