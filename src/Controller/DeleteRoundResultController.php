<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\DeleteRoundResult;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\RoundResultRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DeleteRoundResultController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly RoundResultRepository $resultRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/round-results/{roundId}/delete',
        name: 'delete_round_result',
        methods: ['POST'],
    )]
    public function __invoke(string $roundId, Request $request): JsonResponse
    {
        $round = $this->roundRepository->get($roundId);
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $round->competition->id->toString());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);

        $resultId = is_string($payload['resultId'] ?? null) ? $payload['resultId'] : null;

        if ($resultId === null) {
            return new JsonResponse(['error' => 'resultId is required'], JsonResponse::HTTP_BAD_REQUEST);
        }

        // Idempotent: unknown result id (already deleted offline replay) is fine,
        // but a result belonging to a different round is not.
        $result = $this->resultRepository->find($resultId);

        if ($result !== null && $result->round->id->toString() !== $roundId) {
            return new JsonResponse(['error' => 'Result does not belong to this round'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $this->messageBus->dispatch(new DeleteRoundResult(resultId: $resultId));

        return new JsonResponse(['status' => 'ok']);
    }
}
