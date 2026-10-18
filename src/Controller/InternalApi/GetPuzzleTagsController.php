<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetPuzzleTags;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A puzzle's stored tags and the corrections applied to them (docs/features/internal-api.md, "Puzzle tags"). 404 for
 * an unknown puzzle or a hidden picture.
 */
final class GetPuzzleTagsController extends AbstractController
{
    public function __construct(
        private readonly GetPuzzleTags $getPuzzleTags,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzles/{puzzleId}/tags',
        requirements: ['puzzleId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $puzzleId): JsonResponse
    {
        return new JsonResponse(PuzzleTagsJson::ofPuzzle($this->getPuzzleTags, $puzzleId));
    }
}
