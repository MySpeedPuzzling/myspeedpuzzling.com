<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Query\GetPuzzleMergeReviewQueue;
use SpeedPuzzling\Web\Results\PuzzleMergeReviewCandidate;
use SpeedPuzzling\Web\Results\PuzzleMergeReviewItem;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The review queue of pending puzzle merge requests.
 *
 * Returns ready-to-fetch image URLs alongside the metadata: whether two puzzles
 * are really the same product is usually settled by looking at the artwork, not
 * by comparing names.
 */
final class ListPuzzleMergeRequestsController extends AbstractController
{
    private const int DEFAULT_LIMIT = 25;

    private const int MAX_LIMIT = 100;

    public function __construct(
        private readonly GetPuzzleMergeReviewQueue $getPuzzleMergeReviewQueue,
        private readonly ImageThumbnailTwigExtension $imageThumbnail,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-merge-requests',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $limit = min(
            max($request->query->getInt('limit', self::DEFAULT_LIMIT), 1),
            self::MAX_LIMIT,
        );
        $offset = max($request->query->getInt('offset'), 0);

        $items = $this->getPuzzleMergeReviewQueue->pending($limit, $offset);

        return new JsonResponse([
            'totalPending' => $this->getPuzzleMergeReviewQueue->countPending(),
            'limit' => $limit,
            'offset' => $offset,
            'mergeRequests' => array_map($this->serializeItem(...), $items),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeItem(PuzzleMergeReviewItem $item): array
    {
        return [
            'mergeRequestId' => $item->mergeRequestId,
            'submittedAt' => $item->submittedAt,
            'reporterName' => $item->reporterName,
            'reporterCode' => $item->reporterCode,
            'sourcePuzzleId' => $item->sourcePuzzleId,
            'sourcePuzzleName' => $item->sourcePuzzleName,
            'actionable' => $item->isActionable(),
            'missingPuzzleIds' => $item->missingPuzzleIds,
            'candidates' => array_map($this->serializeCandidate(...), $item->candidates),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCandidate(PuzzleMergeReviewCandidate $candidate): array
    {
        return [
            'puzzleId' => $candidate->puzzleId,
            'name' => $candidate->name,
            'alternativeName' => $candidate->alternativeName,
            'piecesCount' => $candidate->piecesCount,
            'ean' => $candidate->ean,
            'identificationNumber' => $candidate->identificationNumber,
            'manufacturerId' => $candidate->manufacturerId,
            'manufacturerName' => $candidate->manufacturerName,
            'image' => $candidate->image,
            'imageUrl' => $candidate->image !== null
                ? $this->imageThumbnail->thumbnailUrl($candidate->image, 'puzzle_medium')
                : null,
            'approved' => $candidate->approved,
            'addedAt' => $candidate->addedAt,
            'solvedTimesCount' => $candidate->solvedTimesCount,
            'collectionItemsCount' => $candidate->collectionItemsCount,
            'wishListItemsCount' => $candidate->wishListItemsCount,
            'sellSwapItemsCount' => $candidate->sellSwapItemsCount,
        ];
    }
}
