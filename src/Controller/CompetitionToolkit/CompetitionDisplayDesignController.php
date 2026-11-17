<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Query\GetCompetitionDisplayState;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayRateLimit;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A competition display's design `{designVersion, design}` (D-displays-overlays.md §7.2) - fetched by the renderer when
 * the state's `designVersion` grew. Same access rules as the state: the token, 404 when unknown or regenerated, no
 * session, never cached.
 */
final class CompetitionDisplayDesignController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionDisplayState $getCompetitionDisplayState,
        private readonly CompetitionDisplayRateLimit $rateLimit,
    ) {
    }

    #[Route(path: '/display/{token}/design', name: 'competition_display_design', methods: ['GET'])]
    public function __invoke(Request $request, string $token): Response
    {
        // Before anything is read (D-displays-overlays.md §9.1)
        $refused = $this->rateLimit->refusal($request, $token);

        if ($refused !== null) {
            return $refused;
        }

        $response = new JsonResponse($this->getCompetitionDisplayState->designForToken($token));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
