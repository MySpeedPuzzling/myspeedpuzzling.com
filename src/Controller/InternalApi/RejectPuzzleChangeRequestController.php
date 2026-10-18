<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestNotFound;
use SpeedPuzzling\Web\Message\RejectPuzzleChangeRequest;
use SpeedPuzzling\Web\Query\GetPuzzleChangeRequests;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rejects a puzzle change request ("suggest a change").
 *
 * The reason reaches the player who proposed the change, as a notification -
 * so it is written for them, not as an internal note. The optional `decisionNote` is that internal note - kept in the
 * decision log only (details.decisionNote).
 */
final class RejectPuzzleChangeRequestController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetPuzzleChangeRequests $getPuzzleChangeRequests,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-change-requests/{changeRequestId}/reject',
        requirements: ['changeRequestId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $changeRequestId, Request $request): Response
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

        $decisionNote = InternalApiJsonBody::decisionNote($body);
        $puzzleId = $this->getPuzzleChangeRequests->puzzleIdOf($changeRequestId) ?? throw new PuzzleChangeRequestNotFound();

        // A request decided already answers 409 (PuzzleChangeRequestAlreadyReviewed) - nothing changes
        $this->messageBus->dispatch(new RejectPuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: $puzzleId,
            reviewerId: $this->reviewerPlayerId,
            rejectionReason: $rejectionReason,
            decisionSource: MergeDecisionSource::InternalApi,
            decisionNote: $decisionNote,
        ));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
