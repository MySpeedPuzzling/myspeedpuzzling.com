<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\UpsertRoundResult;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class UpsertRoundResultController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/round-results/{roundId}/upsert',
        name: 'upsert_round_result',
        methods: ['POST'],
    )]
    public function __invoke(string $roundId, Request $request): JsonResponse
    {
        $round = $this->roundRepository->get($roundId);
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $round->competition->id->toString());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);

        $resultId = is_string($payload['resultId'] ?? null) ? $payload['resultId'] : null;
        $participantId = is_string($payload['participantId'] ?? null) ? $payload['participantId'] : null;
        $teamId = is_string($payload['teamId'] ?? null) ? $payload['teamId'] : null;
        $entrantName = is_string($payload['entrantName'] ?? null) ? trim($payload['entrantName']) : null;
        $secondsToSolve = is_numeric($payload['secondsToSolve'] ?? null) ? (int) $payload['secondsToSolve'] : null;
        $missingPieces = is_numeric($payload['missingPieces'] ?? null) ? (int) $payload['missingPieces'] : null;

        if ($resultId === null || !Uuid::isValid($resultId)) {
            return new JsonResponse(['error' => 'resultId must be a valid UUID'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($participantId === null && $teamId === null && ($entrantName === null || $entrantName === '')) {
            return new JsonResponse(['error' => 'Provide participantId, teamId or entrantName'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($secondsToSolve !== null && $secondsToSolve <= 0) {
            return new JsonResponse(['error' => 'secondsToSolve must be positive'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($missingPieces !== null && $missingPieces < 0) {
            return new JsonResponse(['error' => 'missingPieces must not be negative'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $this->messageBus->dispatch(new UpsertRoundResult(
            resultId: $resultId,
            roundId: $roundId,
            participantId: $participantId,
            teamId: $teamId,
            entrantName: $entrantName !== '' ? $entrantName : null,
            secondsToSolve: $secondsToSolve,
            missingPieces: $missingPieces,
        ));

        return new JsonResponse(['status' => 'ok', 'resultId' => $resultId]);
    }
}
