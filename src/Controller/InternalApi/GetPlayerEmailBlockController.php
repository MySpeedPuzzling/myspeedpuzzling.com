<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetPlayerEmailSuppression;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Whether the player's current address is suppressed - blocked by hand (`source: manual`) or after bounces
 * (`hard_bounce`, `domain_not_found`). 404 when nothing holds mail back.
 */
final class GetPlayerEmailBlockController extends AbstractController
{
    public function __construct(
        private readonly GetPlayerEmailSuppression $getPlayerEmailSuppression,
    ) {
    }

    #[Route(
        path: '/internal-api/players/{playerId}/email-block',
        requirements: ['playerId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $playerId): JsonResponse
    {
        $suppression = $this->getPlayerEmailSuppression->ofPlayer(strtolower($playerId));

        if ($suppression === null) {
            throw new NotFoundHttpException('The player\'s address is not suppressed.');
        }

        return new JsonResponse($suppression);
    }
}
