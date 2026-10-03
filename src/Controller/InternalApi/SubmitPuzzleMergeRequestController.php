<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Files a duplicate report for puzzles found by an automated review (e.g. the
 * same puzzle left twice under one brand after a brand merge). The reviewer
 * player is the reporter, like a moderator merging from the approval queue -
 * the request is then settled through the usual approve/reject endpoints.
 */
final class SubmitPuzzleMergeRequestController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-merge-requests',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to file the report as.',
            );
        }

        $puzzleIds = InternalApiJsonBody::uuidList(InternalApiJsonBody::parse($request), 'puzzleIds', 2);
        $mergeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            sourcePuzzleId: $puzzleIds[0],
            reporterId: $this->reviewerPlayerId,
            duplicatePuzzleIds: array_slice($puzzleIds, 1),
        ));

        return new JsonResponse(['mergeRequestId' => $mergeRequestId], Response::HTTP_CREATED);
    }
}
