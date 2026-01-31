<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Api\V1;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GetPlayerResultsController extends AbstractController
{
    public function __construct(
        private readonly GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
    ) {
    }

    #[Route(
        path: '/api/v1/players/{playerId}/results',
        name: 'api_v1_player_results',
        methods: ['GET'],
    )]
    public function __invoke(string $playerId, Request $request): JsonResponse
    {
        $type = $request->query->getString('type', 'solo');

        try {
            $results = match ($type) {
                'solo' => $this->getPlayerSolvedPuzzles->soloByPlayerId($playerId),
                'duo' => $this->getPlayerSolvedPuzzles->duoByPlayerId($playerId),
                'team' => $this->getPlayerSolvedPuzzles->teamByPlayerId($playerId),
                default => $this->getPlayerSolvedPuzzles->soloByPlayerId($playerId),
            };
        } catch (PlayerNotFound) {
            return $this->json(['error' => 'Player not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'player_id' => $playerId,
            'type' => $type,
            'count' => count($results),
            'results' => array_map(
                static fn(SolvedPuzzle $puzzle) => [
                    'time_id' => $puzzle->timeId,
                    'puzzle_id' => $puzzle->puzzleId,
                    'puzzle_name' => $puzzle->puzzleName,
                    'manufacturer_name' => $puzzle->manufacturerName,
                    'pieces_count' => $puzzle->piecesCount,
                    'time_seconds' => $puzzle->time,
                    'finished_at' => $puzzle->finishedAt->format('c'),
                    'first_attempt' => $puzzle->firstAttempt,
                    'puzzle_image' => $puzzle->puzzleImage,
                    'comment' => $puzzle->comment,
                ],
                $results,
            ),
        ]);
    }
}
