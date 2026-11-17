<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A competition display's state now (D-displays-overlays.md §7.2): the token in the path is the access right - no
 * locale (the URL never changes), no session (the `stateless` firewall), never cached. An unknown or regenerated token
 * is a 404 (CompetitionDisplayNotFound). A draft event's display works - a rehearsal before publishing.
 */
final class CompetitionDisplayStateController extends AbstractController
{
    public function __construct(
        private readonly CompetitionDisplayPayload $payload,
    ) {
    }

    #[Route(path: '/display/{token}/state', name: 'competition_display_state', methods: ['GET'])]
    public function __invoke(string $token): JsonResponse
    {
        $response = new JsonResponse($this->payload->forToken($token));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
