<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Approves a puzzle merge, recording who decided it and why.
 *
 * A merge deletes puzzles and moves solving times onto the survivor, so the
 * handler writes a full before/after snapshot to the audit trail first. The note
 * and confidence supplied here are stored with it - they are what make a merge
 * decided in bulk reviewable afterwards.
 */
final class ApprovePuzzleMergeRequestController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-merge-requests/{mergeRequestId}/approve',
        methods: ['POST'],
    )]
    public function __invoke(string $mergeRequestId, Request $request): Response
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to credit the merge to.',
            );
        }

        $body = InternalApiJsonBody::parse($request);

        $survivorPuzzleId = InternalApiJsonBody::requiredString($body, 'survivorPuzzleId');
        $mergedName = InternalApiJsonBody::requiredString($body, 'mergedName');
        $mergedPiecesCount = $body['mergedPiecesCount'] ?? null;

        if (is_int($mergedPiecesCount) === false || $mergedPiecesCount <= 0) {
            throw new BadRequestHttpException('"mergedPiecesCount" must be a positive integer.');
        }

        $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: $this->reviewerPlayerId,
            survivorPuzzleId: $survivorPuzzleId,
            mergedName: $mergedName,
            mergedEan: InternalApiJsonBody::optionalString($body, 'mergedEan'),
            mergedIdentificationNumber: InternalApiJsonBody::optionalString($body, 'mergedIdentificationNumber'),
            mergedPiecesCount: $mergedPiecesCount,
            mergedManufacturerId: InternalApiJsonBody::optionalString($body, 'mergedManufacturerId'),
            selectedImagePuzzleId: InternalApiJsonBody::optionalString($body, 'selectedImagePuzzleId'),
            decisionSource: MergeDecisionSource::InternalApi,
            decisionConfidence: InternalApiJsonBody::confidence($body),
            decisionNote: InternalApiJsonBody::optionalString($body, 'decisionNote'),
        ));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
