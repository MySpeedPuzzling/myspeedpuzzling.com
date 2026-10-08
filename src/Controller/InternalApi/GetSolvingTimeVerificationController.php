<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SolvingTimeVerificationJson;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A solving time's verification state: is it flagged ("needs verification", not counted anywhere), its case in the
 * time verification queue and who was told about the current mark.
 */
final class GetSolvingTimeVerificationController extends AbstractController
{
    public function __construct(
        private readonly SolvingTimeVerificationJson $solvingTimeVerificationJson,
    ) {
    }

    #[Route(
        path: '/internal-api/solving-times/{timeId}/verification',
        requirements: ['timeId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $timeId): JsonResponse
    {
        return new JsonResponse($this->solvingTimeVerificationJson->of($timeId));
    }
}
