<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Api;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GetPlayerStatisticsController extends AbstractController
{
    #[Route(path: '/api/v0/players/{playerId}/statistics', methods: ['GET'])]
    public function __invoke(
        string $playerId,
        Request $request,
    ): Response {
        if ($request->query->get('token') === null) {
            return $this->json(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            return $this->json([
                'last_activity' => '2024-09-07 12:00:00',
                'last_activity_ago' => '3 days ago',
                'total' => [
                    'puzzles_completed' => 200,
                    'unique_puzzles_completed' => 120,
                    'time' => '1:23:45',
                    'time_seconds' => 3600,
                    'pieces_placed' => 750000,
                ],
                'solo' => [
                    '500_pieces' => [
                        'puzzles_completed' => 500,
                        'unique_puzzles_completed' => 400,
                        'pieces_placed' => 250000,
                        'time' => '1:23:45',
                        'time_seconds' => 2500000,
                        'average' => [
                            'time' => '1:10:00',
                            'time_seconds' => 4200,
                            'ppm' => 125,
                        ],
                        'average_first_attempt' => [
                            'time' => '1:10:00',
                            'time_seconds' => 4200,
                            'ppm' => 125,
                        ],
                        'top' => [
                            'time' => '1:10:00',
                            'time_seconds' => 4200,
                            'ppm' => 125,
                            'puzzle_name' => 'Some puzzle name',
                            'puzzle_image' => 'image.jpg',
                            'puzzle_brand' => 'Ravensburger',
                        ],
                        'top_first_attempt' => [
                            'time' => '1:10:00',
                            'time_seconds' => 4200,
                            'ppm' => 125,
                            'puzzle_name' => 'Some puzzle name',
                            'puzzle_image' => 'image.jpg',
                            'puzzle_brand' => 'Ravensburger',
                        ],
                    ],
                    '1000_pieces' => [
                        'puzzles_completed' => 500,
                        'unique_puzzles_completed' => 400,
                        'pieces_placed' => 250000,
                        'time' => '1:23:45',
                        'time_seconds' => 2500000,
                        'average' => [
                            'time' => '1:10:00',
                            'time_seconds' => 4200,
                            'ppm' => 125,
                        ],
                        'average_first_attempt' => [
                            'time' => '1:10:00',
                            'time_seconds' => 4200,
                            'ppm' => 125,
                        ],
                        'top' => [
                            'time' => '1:10:00',
                            'time_seconds' => 4200,
                            'ppm' => 125,
                            'puzzle_name' => 'Some puzzle name',
                            'puzzle_image' => 'image.jpg',
                            'puzzle_brand' => 'Ravensburger',
                        ],
                        'top_first_attempt' => [
                            'time' => '1:10:00',
                            'time_seconds' => 4200,
                            'ppm' => 125,
                            'puzzle_name' => 'Some puzzle name',
                            'puzzle_image' => 'image.jpg',
                            'puzzle_brand' => 'Ravensburger',
                        ],
                    ],
                ],
                'duo' => (object) [],
                'team' => (object) [],
            ]);
        } catch (PlayerNotFound) {
            return $this->json(['error' => 'Player not found.'], Response::HTTP_NOT_FOUND);
        }
    }
}
