<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetPuzzleImageAnalyses;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Every stored analysis of a puzzle's box image, payloads included - for debugging (docs/features/internal-api.md,
 * "Puzzle image analysis"). 404 for an unknown puzzle or a hidden image.
 */
final class GetPuzzleImageAnalysesController extends AbstractController
{
    public function __construct(
        private readonly GetPuzzleImageAnalyses $getPuzzleImageAnalyses,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzles/{puzzleId}/image-analysis',
        requirements: ['puzzleId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $puzzleId): JsonResponse
    {
        $analyses = [];

        foreach ($this->getPuzzleImageAnalyses->ofPuzzle($puzzleId) as $analyzer => $analysis) {
            $analyses[$analyzer] = PuzzleImageAnalysisJson::of($analysis);
        }

        return new JsonResponse([
            'puzzleId' => strtolower($puzzleId),
            // An object even when empty
            'analyses' => (object) $analyses,
        ]);
    }
}
