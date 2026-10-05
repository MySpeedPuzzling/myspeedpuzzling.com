<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Services\InternalApi\RoundPuzzlesSync;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The round's puzzles become exactly `puzzleIds` (RoundPuzzlesSync): one round per category per puzzle per
 * competition, else 409 and nothing changes.
 */
final class SetRoundPuzzlesController extends AbstractController
{
    public function __construct(
        private readonly RoundPuzzlesSync $roundPuzzlesSync,
        private readonly GetAdminCompetitions $getAdminCompetitions,
    ) {
    }

    #[Route(
        path: '/internal-api/rounds/{roundId}/puzzles',
        requirements: ['roundId' => InternalApiInput::ID_REQUIREMENT],
        methods: ['PUT'],
    )]
    public function __invoke(string $roundId, Request $request): JsonResponse
    {
        $input = InternalApiInput::fromRequest($request, ['puzzleIds']);
        $puzzleIds = $input->idList('puzzleIds');

        if ($puzzleIds === null) {
            $input->addError('puzzleIds', 'is required - a list of puzzle ids, [] removes every puzzle.');
        }

        $input->throwIfInvalid();
        assert($puzzleIds !== null);

        $this->roundPuzzlesSync->sync($roundId, $puzzleIds);

        return new JsonResponse($this->getAdminCompetitions->round($roundId)->toArray());
    }
}
