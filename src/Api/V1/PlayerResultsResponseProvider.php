<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<PlayerResultsResponse>
 */
final readonly class PlayerResultsResponseProvider implements ProviderInterface
{
    public function __construct(
        private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        private RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerResultsResponse
    {
        /** @var string $playerId */
        $playerId = $uriVariables['playerId'];

        $request = $this->requestStack->getCurrentRequest();
        $type = $request?->query->getString('type', 'solo') ?? 'solo';

        $results = match ($type) {
            'duo' => $this->getPlayerSolvedPuzzles->duoByPlayerId($playerId),
            'team' => $this->getPlayerSolvedPuzzles->teamByPlayerId($playerId),
            default => $this->getPlayerSolvedPuzzles->soloByPlayerId($playerId),
        };

        return new PlayerResultsResponse(
            player_id: $playerId,
            type: $type,
            count: count($results),
            results: array_map(
                static fn(SolvedPuzzle $puzzle) => new PlayerResultResponse(
                    time_id: $puzzle->timeId,
                    puzzle_id: $puzzle->puzzleId,
                    puzzle_name: $puzzle->puzzleName,
                    manufacturer_name: $puzzle->manufacturerName,
                    pieces_count: $puzzle->piecesCount,
                    time_seconds: $puzzle->time,
                    finished_at: $puzzle->finishedAt?->format('c'),
                    first_attempt: $puzzle->firstAttempt,
                    puzzle_image: $puzzle->puzzleImage,
                    comment: $puzzle->comment,
                ),
                $results,
            ),
        );
    }
}
