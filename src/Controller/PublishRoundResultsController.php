<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\PublishRoundResults;
use SpeedPuzzling\Web\Message\UnpublishRoundResults;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class PublishRoundResultsController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/round-results/{roundId}/publish',
        name: 'publish_round_results',
        methods: ['POST'],
    )]
    public function __invoke(string $roundId, Request $request): JsonResponse
    {
        $round = $this->roundRepository->get($roundId);
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $round->competition->id->toString());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);

        $publish = ($payload['published'] ?? true) === true;
        $notifyParticipants = ($payload['notifyParticipants'] ?? true) === true;

        if ($publish) {
            $this->messageBus->dispatch(new PublishRoundResults(
                roundId: $roundId,
                notifyParticipants: $notifyParticipants,
            ));
        } else {
            $this->messageBus->dispatch(new UnpublishRoundResults(roundId: $roundId));
        }

        return new JsonResponse(['status' => 'ok', 'published' => $publish]);
    }
}
