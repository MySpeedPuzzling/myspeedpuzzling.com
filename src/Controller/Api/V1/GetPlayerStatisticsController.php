<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Api\V1;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerStatistics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GetPlayerStatisticsController extends AbstractController
{
    public function __construct(
        private readonly GetPlayerStatistics $getPlayerStatistics,
    ) {
    }

    #[Route(
        path: '/api/v1/players/{playerId}/statistics',
        name: 'api_v1_player_statistics',
        methods: ['GET'],
    )]
    public function __invoke(string $playerId): JsonResponse
    {
        try {
            $solo = $this->getPlayerStatistics->solo($playerId);
            $duo = $this->getPlayerStatistics->duo($playerId);
            $team = $this->getPlayerStatistics->team($playerId);
        } catch (PlayerNotFound) {
            return $this->json(['error' => 'Player not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'player_id' => $playerId,
            'solo' => [
                'total_seconds' => $solo->totalSeconds,
                'total_pieces' => $solo->totalPieces,
                'solved_puzzles_count' => $solo->solvedPuzzlesCount,
            ],
            'duo' => [
                'total_seconds' => $duo->totalSeconds,
                'total_pieces' => $duo->totalPieces,
                'solved_puzzles_count' => $duo->solvedPuzzlesCount,
            ],
            'team' => [
                'total_seconds' => $team->totalSeconds,
                'total_pieces' => $team->totalPieces,
                'solved_puzzles_count' => $team->solvedPuzzlesCount,
            ],
        ]);
    }
}
