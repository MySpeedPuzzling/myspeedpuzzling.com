<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Api;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\SolvingTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GetPlayerResultsController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private PuzzlingTimeFormatter $puzzlingTimeFormatter,
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
            $soloResults = array_map(function(SolvedPuzzle $result): array {
                return [
                    'id' => $result->timeId,
                    'time_seconds' => $result->time,
                    'time' => $this->puzzlingTimeFormatter->formatTime($result->time),
                    'ppm' => (new SolvingTime($result->time))->calculatePpm($result->piecesCount),
                    'player_name' => $result->playerName,
                    'puzzle_name' => $result->puzzleName,
                    'puzzle_pieces' => $result->piecesCount,
                    'puzzle_image' => $result->puzzleImage,
                    'puzzle_brand' => $result->manufacturerName,
                    'finished_at' => $result->finishedAt->format(DATE_ATOM),
                    'solved_times' => $result->solvedTimes,
                ];
            }, $this->getPlayerSolvedPuzzles->soloByPlayerId($playerId));

            $duoResults = array_map(function(SolvedPuzzle $result): array {
                assert($result->players !== null);
                $players = array_map(function(Puzzler $player): array {
                    return [
                        'id' => $player->playerId,
                        'name' => $player->playerName,
                    ];
                }, $result->players);

                return [
                    'id' => $result->timeId,
                    'time_seconds' => $result->time,
                    'time' => $this->puzzlingTimeFormatter->formatTime($result->time),
                    'ppm' => (new SolvingTime($result->time))->calculatePpm($result->piecesCount, 2),
                    'players' => $players,
                    'puzzle_pieces' => $result->piecesCount,
                    'puzzle_image' => $result->puzzleImage,
                    'puzzle_brand' => $result->manufacturerName,
                    'finished_at' => $result->finishedAt->format(DATE_ATOM),
                    'solved_times' => $result->solvedTimes,
                ];
            }, $this->getPlayerSolvedPuzzles->duoByPlayerId($playerId));

            $teamResults = array_map(function(SolvedPuzzle $result): array {
                assert($result->players !== null);
                $players = array_map(function(Puzzler $player): array {
                    return [
                        'id' => $player->playerId,
                        'name' => $player->playerName,
                    ];
                }, $result->players);

                return [
                    'id' => $result->timeId,
                    'time_seconds' => $result->time,
                    'time' => $this->puzzlingTimeFormatter->formatTime($result->time),
                    'ppm' => (new SolvingTime($result->time))->calculatePpm($result->piecesCount, count($result->players)),
                    'players' => $players,
                    'puzzle_pieces' => $result->piecesCount,
                    'puzzle_image' => $result->puzzleImage,
                    'puzzle_brand' => $result->manufacturerName,
                    'finished_at' => $result->finishedAt->format(DATE_ATOM),
                    'solved_times' => $result->solvedTimes,
                ];
            }, $this->getPlayerSolvedPuzzles->teamByPlayerId($playerId));
        } catch (PlayerNotFound) {
            return $this->json(['error' => 'Player not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'solo' => $soloResults,
            'duo' => $duoResults,
            'team' => $teamResults,
        ]);
    }
}
