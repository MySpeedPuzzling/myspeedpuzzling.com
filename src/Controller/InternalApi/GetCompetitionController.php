<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One competition by id or slug with everything the API can edit: its fields, approval state, maintainers, rounds
 * with their puzzles and the competition's own (tagged) puzzles.
 */
final class GetCompetitionController extends AbstractController
{
    public function __construct(
        private readonly GetAdminCompetitions $getAdminCompetitions,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions/{idOrSlug}',
        requirements: ['idOrSlug' => '[^/]+'],
        methods: ['GET'],
    )]
    public function __invoke(string $idOrSlug): JsonResponse
    {
        return new JsonResponse($this->getAdminCompetitions->detail($idOrSlug)->toArray());
    }
}
