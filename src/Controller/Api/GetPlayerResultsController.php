<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Api;

use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GetPlayerResultsController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
    ) {
    }

    #[Route(path: '/api/v0/players/{playerId}/results', methods: ['GET'])]
    public function __invoke(
        string $playerId,
        Request $request,
    ): Response {
        if ($request->query->get('token') === null) {
            return $this->json(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        $results = $this->getPlayerSolvedPuzzles->soloByPlayerId($playerId);

        return $this->json([
            ['resultId' => null],
            ['resultId' => null],
        ]);
    }
}
