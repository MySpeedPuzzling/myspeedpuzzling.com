<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Api;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Services\RelativeTimeFormatter;
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
        readonly private RelativeTimeFormatter $relativeTimeFormatter,
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
            $soloResults = array_map(
                fn (SolvedPuzzle $result): array => $this->resultToApiShape($result),
                $this->getPlayerSolvedPuzzles->soloByPlayerId($playerId),
            );

            $duoResults = array_map(
                fn (SolvedPuzzle $result): array => $this->resultToApiShape($result),
                $this->getPlayerSolvedPuzzles->duoByPlayerId($playerId),
            );

            $teamResults = array_map(
                fn (SolvedPuzzle $result): array => $this->resultToApiShape($result),
                $this->getPlayerSolvedPuzzles->teamByPlayerId($playerId),
            );

            return $this->json([
                'solo' => $soloResults,
                'duo' => $duoResults,
                'team' => $teamResults,
            ]);
        } catch (PlayerNotFound) {
            return $this->json(['error' => 'Player not found.'], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * @return array<mixed>
     */
    private function resultToApiShape(SolvedPuzzle $result): array
    {
        $ppm = (new SolvingTime($result->time))->calculatePpm(
            $result->piecesCount,
            $result->players === null ? 1 : count($result->players),
        );

        $data = [
            'id' => $result->timeId,
            'time_seconds' => $result->time,
            'time' => $result->time !== null ? $this->puzzlingTimeFormatter->formatTime($result->time) : null,
            'ppm' => $result->time !== null ? $ppm : null,
            'is_relax_mode' => $result->time === null,
            'first_attempt' => $result->firstAttempt,
            'puzzle_id' => $result->puzzleId,
            'puzzle_name' => $result->puzzleName,
            'puzzle_pieces' => $result->piecesCount,
            'puzzle_image' => $result->puzzleImage,
            'puzzle_brand' => $result->manufacturerName,
            'finished_at' => $result->finishedAt?->format(DATE_ATOM),
            'finished_at_alt_format' => $result->finishedAt?->format('d/m/Y'),
            'finished_at_ago' => $result->finishedAt !== null ? $this->relativeTimeFormatter->formatDiff($result->finishedAt) : null,
            'solved_times' => $result->solvedTimes,
        ];

        if ($result->players === null) {
            $data['player_name'] = $result->playerName;
        } else {
            $data['players'] = array_map(function (Puzzler $player): array {
                return [
                    'id' => $player->playerId,
                    'name' => $player->playerName,
                ];
            }, $result->players);
        }

        return $data;
    }
}
