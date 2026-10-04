<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Query\GetPendingPuzzleProposals;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Value\EanList;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Files a change proposal for a puzzle found by an automated review (e.g. an EAN typed
 * without its zeros), so a moderator decides it like any player's "Suggest a change".
 * The reviewer player is the reporter. A field left out keeps the puzzle's current value,
 * so the review shows only what this proposal changes.
 */
final class SubmitPuzzleChangeRequestController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetPuzzleOverview $getPuzzleOverview,
        private readonly GetPendingPuzzleProposals $getPendingPuzzleProposals,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-change-requests',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to file the proposal as.',
            );
        }

        $body = InternalApiJsonBody::parse($request);
        $puzzleId = InternalApiJsonBody::requiredString($body, 'puzzleId');

        if (Uuid::isValid($puzzleId) === false) {
            throw new BadRequestHttpException('"puzzleId" must be an id.');
        }

        $puzzle = $this->getPuzzleOverview->byId($puzzleId);

        $name = InternalApiJsonBody::optionalString($body, 'name') ?? $puzzle->puzzleName;
        $manufacturerId = InternalApiJsonBody::optionalString($body, 'manufacturerId') ?? $puzzle->manufacturerId;
        $ean = InternalApiJsonBody::optionalString($body, 'ean') ?? $puzzle->puzzleEan;
        $identificationNumber = InternalApiJsonBody::optionalString($body, 'identificationNumber') ?? $puzzle->puzzleIdentificationNumber;
        $piecesCount = $body['piecesCount'] ?? $puzzle->piecesCount;

        if (is_int($piecesCount) === false || $piecesCount < 10 || $piecesCount > 25000) {
            throw new BadRequestHttpException('"piecesCount" must be a whole number from 10 to 25000.');
        }

        if (Uuid::isValid($manufacturerId) === false) {
            throw new BadRequestHttpException('"manufacturerId" must be an id.');
        }

        $invalidCodes = EanList::invalidCodes($ean ?? '', $puzzle->puzzleEan);
        if ($invalidCodes !== []) {
            throw new BadRequestHttpException(sprintf(
                '"ean" holds codes that are not EAN/UPC codes: %s.',
                implode(', ', array_column($invalidCodes, 'code')),
            ));
        }

        $changes = $name !== $puzzle->puzzleName
            || $manufacturerId !== $puzzle->manufacturerId
            || $piecesCount !== $puzzle->piecesCount
            || $ean !== $puzzle->puzzleEan
            || $identificationNumber !== $puzzle->puzzleIdentificationNumber;

        if ($changes === false) {
            throw new BadRequestHttpException('Nothing to change - every given field equals the puzzle as it is.');
        }

        // Like the web form: one open proposal per puzzle. Answered without an exception, so a
        // caller filing in bulk does not turn a known conflict into a logged error.
        if ($this->getPendingPuzzleProposals->hasPendingForPuzzle($puzzleId)) {
            return new JsonResponse(
                ['error' => 'The puzzle already has a pending change or merge request.'],
                Response::HTTP_CONFLICT,
            );
        }

        $changeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new SubmitPuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: $puzzleId,
            reporterId: $this->reviewerPlayerId,
            proposedName: $name,
            proposedManufacturerId: $manufacturerId,
            proposedPiecesCount: $piecesCount,
            proposedEan: $ean,
            proposedIdentificationNumber: $identificationNumber,
            proposedPhoto: null,
        ));

        return new JsonResponse(['changeRequestId' => $changeRequestId], Response::HTTP_CREATED);
    }
}
