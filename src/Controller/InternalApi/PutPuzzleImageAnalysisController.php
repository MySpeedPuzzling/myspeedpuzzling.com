<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\RecordPuzzleImageAnalysis;
use SpeedPuzzling\Web\Query\GetPuzzleImageAnalyses;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisInput;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stores one analysis of a puzzle's box image as its current one for the analyzer (docs/features/internal-api.md,
 * "Puzzle image analysis"). Body: `analyzerVersion`, `imageFingerprint` (from the image list), `analyzedAt` (optional)
 * and `result` (a JSON object, at most 64 KB). Answers the stored analysis; 404 for an unknown puzzle or a hidden image,
 * 409 when the image changed since it was listed, 422 for an invalid analysis (an unknown analyzer included).
 */
final class PutPuzzleImageAnalysisController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetPuzzleImageAnalyses $getPuzzleImageAnalyses,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzles/{puzzleId}/image-analysis/{analyzer}',
        requirements: ['puzzleId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['PUT'],
    )]
    public function __invoke(string $puzzleId, string $analyzer, Request $request): JsonResponse
    {
        $input = PuzzleImageAnalysisInput::fromBody($puzzleId, $analyzer, InternalApiJsonBody::parse($request));

        $this->messageBus->dispatch(new RecordPuzzleImageAnalysis($input));

        $stored = $this->getPuzzleImageAnalyses->one($input->puzzleId, $input->analyzer);
        assert($stored !== null);

        return new JsonResponse(PuzzleImageAnalysisJson::of($stored));
    }
}
