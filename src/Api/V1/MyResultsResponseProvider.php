<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Security\ApiUser;
use SpeedPuzzling\Web\Services\Api\PuzzleResponseFactory;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<MyResultsResponse>
 */
final readonly class MyResultsResponseProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        private RequestStack $requestStack,
        private PuzzleResponseFactory $puzzleResponseFactory,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MyResultsResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $playerId = $user->getPlayer()->id->toString();

        $request = $this->requestStack->getCurrentRequest();
        $type = $request?->query->getString('type', 'solo') ?? 'solo';

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

        return new MyResultsResponse(
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
