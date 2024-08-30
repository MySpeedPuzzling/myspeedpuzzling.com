<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Api;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
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

        try {
            $results = $this->getPlayerSolvedPuzzles->soloByPlayerId($playerId);
        } catch (PlayerNotFound) {
            return $this->json(['error' => 'Player not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = [];

        foreach ($results as $result) {
            $data[] = [
                'id' => $result->timeId,
                'time_seconds' => $result->time,
                'puzzle_name' => $result->puzzleName,
                'puzzle_pieces' => $result->piecesCount,
                'puzzle_image' => $result->puzzleImage,
                'puzzle_brand' => $result->manufacturerName,
                'type' => 'solo',
                'team' => null,
                'finished_at' => $result->finishedAt->format(DATE_ATOM),
                'solved_times' => $result->solvedTimes,
            ];
        }

        return $this->json($data);
    }
}
