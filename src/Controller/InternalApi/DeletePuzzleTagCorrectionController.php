<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\RefreshPuzzleTags;
use SpeedPuzzling\Web\Message\RemovePuzzleTagCorrection;
use SpeedPuzzling\Web\Query\GetPuzzleTags;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Removes the correction of one tag of one puzzle - the analysis decides that key again (docs/features/internal-api.md,
 * "Puzzle tags"). Idempotent: no correction is fine. The puzzle's tags are rewritten at once; answers them with the
 * remaining corrections. 404 for an unknown puzzle or a hidden picture.
 */
final class DeletePuzzleTagCorrectionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetPuzzleTags $getPuzzleTags,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzles/{puzzleId}/tag-corrections/{tagKey}',
        requirements: ['puzzleId' => FirstTryConflictsController::ID_REQUIREMENT, 'tagKey' => '[a-z0-9_.]{1,100}'],
        methods: ['DELETE'],
    )]
    public function __invoke(string $puzzleId, string $tagKey): JsonResponse
    {
        $this->messageBus->dispatch(new RemovePuzzleTagCorrection($puzzleId, $tagKey));

        // Its own transaction - the writer reads what the removal committed
        $this->messageBus->dispatch(new RefreshPuzzleTags([$puzzleId]));

        return new JsonResponse(PuzzleTagsJson::ofPuzzle($this->getPuzzleTags, $puzzleId));
    }
}
