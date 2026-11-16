<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Message\RevertPuzzleMergeRequest;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Undoes an approved puzzle merge from its audit - the same as "Revert this merge" on the admin page
 * (RevertPuzzleMergeRequestHandler). A refused revert (not approved, merged before the audit, several puzzles at
 * once, already reverted) answers 422 with the reason.
 */
final class RevertPuzzleMergeRequestController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-merge-requests/{mergeRequestId}/revert',
        methods: ['POST'],
    )]
    public function __invoke(string $mergeRequestId, Request $request): Response
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to credit the revert to.',
            );
        }

        $body = InternalApiJsonBody::parse($request);
        $survivorPuzzleId = $body['survivorPuzzleId'] ?? null;
        $note = $body['note'] ?? null;

        if (is_string($survivorPuzzleId) === false || trim($survivorPuzzleId) === '') {
            throw new BadRequestHttpException('"survivorPuzzleId" (the puzzle the merge kept) is required.');
        }

        if ($note !== null && is_string($note) === false) {
            throw new BadRequestHttpException('"note" must be a string.');
        }

        $this->messageBus->dispatch(new RevertPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            adminId: $this->reviewerPlayerId,
            survivorPuzzleId: trim($survivorPuzzleId),
            note: $note !== null && trim($note) !== '' ? trim($note) : null,
            decisionSource: MergeDecisionSource::InternalApi,
        ));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
