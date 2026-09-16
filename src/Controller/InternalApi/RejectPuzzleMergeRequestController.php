<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Message\RejectPuzzleMergeRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rejects a puzzle merge request.
 *
 * The reason reaches the player who reported the duplicate, as a notification -
 * so it is written for them, not as an internal note.
 */
final class RejectPuzzleMergeRequestController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-merge-requests/{mergeRequestId}/reject',
        methods: ['POST'],
    )]
    public function __invoke(string $mergeRequestId, Request $request): Response
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to credit the review to.',
            );
        }

        $body = InternalApiJsonBody::parse($request);
        $rejectionReason = $body['rejectionReason'] ?? null;

        if (is_string($rejectionReason) === false || trim($rejectionReason) === '') {
            throw new BadRequestHttpException('"rejectionReason" is required.');
        }

        $this->messageBus->dispatch(new RejectPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: $this->reviewerPlayerId,
            rejectionReason: $rejectionReason,
        ));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
