<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\Api\PuzzleResponseFactory;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<PlayerResultsResponse>
 */
final readonly class PlayerResultsResponseProvider implements ProviderInterface
{
    public function __construct(
        private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        private RequestStack $requestStack,
        private GetPlayerProfile $getPlayerProfile,
        private PuzzleResponseFactory $puzzleResponseFactory,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerResultsResponse
    {
        /** @var string $playerId */
        $playerId = $uriVariables['playerId'];

        $profile = $this->getPlayerProfile->byId($playerId);

        $request = $this->requestStack->getCurrentRequest();
        $type = $request?->query->getString('type', 'solo') ?? 'solo';

        if ($profile->isPrivate) {
            return new PlayerResultsResponse(
                playerId: $playerId,
                type: $type,
                count: 0,
                results: [],
            );
        }

        $results = match ($type) {
            'duo' => $this->getPlayerSolvedPuzzles->duoByPlayerId($playerId),
            'team' => $this->getPlayerSolvedPuzzles->teamByPlayerId($playerId),
            default => $this->getPlayerSolvedPuzzles->soloByPlayerId($playerId),
        };

        // One batch per list, whatever its size: community statistics (public)
        // and difficulty (token owner member); a result list has no solves or
        // prediction object
        $insights = $this->puzzleResponseFactory->insightsFor(
            array_map(static fn (SolvedPuzzle $puzzle): string => $puzzle->puzzleId, $results),
            solvesOfPlayerId: null,
            includePrediction: false,
        );

        return new PlayerResultsResponse(
            playerId: $playerId,
            type: $type,
            count: count($results),
            results: array_map(
                static fn(SolvedPuzzle $puzzle) => new PlayerResultResponse(
                    timeId: $puzzle->timeId,
                    puzzleId: $puzzle->puzzleId,
                    puzzleName: $puzzle->puzzleName,
                    manufacturerName: $puzzle->manufacturerName,
                    piecesCount: $puzzle->piecesCount,
                    timeSeconds: $puzzle->time,
                    finishedAt: $puzzle->finishedAt?->format('c'),
                    firstAttempt: $puzzle->firstAttempt,
                    puzzleImage: $puzzle->puzzleImage,
                    comment: $puzzle->comment,
                    statistics: $insights->statistics($puzzle->puzzleId),
                    difficulty: $insights->difficulty($puzzle->puzzleId),
                ),
                $results,
            ),
        );
    }
}
