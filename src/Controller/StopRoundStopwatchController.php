<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\StopRoundStopwatch;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class StopRoundStopwatchController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/zastavit-stopky-kola/{roundId}',
            'en' => '/en/stop-round-stopwatch/{roundId}',
            'es' => '/es/stop-round-stopwatch/{roundId}',
            'ja' => '/ja/stop-round-stopwatch/{roundId}',
            'fr' => '/fr/stop-round-stopwatch/{roundId}',
            'de' => '/de/stop-round-stopwatch/{roundId}',
        ],
        name: 'stop_round_stopwatch',
        methods: ['POST'],
    )]
    public function __invoke(string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $round->competition->id->toString());

        $this->messageBus->dispatch(new StopRoundStopwatch(roundId: $roundId));

        return $this->redirectToRoute('manage_round_stopwatch', ['roundId' => $roundId]);
    }
}
