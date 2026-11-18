<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetCompetitionDivisions;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An event's divisions (docs/features/internal-api.md "Divisions", shared-puzzles-and-divisions.md D1): in their order,
 * each with the rounds using it and how many round entries carry it.
 */
final class GetCompetitionDivisionsController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly GetCompetitionDivisions $getCompetitionDivisions,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions/{competitionId}/divisions',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId): JsonResponse
    {
        $competitionId = $this->competitionRepository->get($competitionId)->id->toString();

        return new JsonResponse([
            'competitionId' => $competitionId,
            'divisions' => $this->getCompetitionDivisions->ofCompetition($competitionId),
        ]);
    }
}
