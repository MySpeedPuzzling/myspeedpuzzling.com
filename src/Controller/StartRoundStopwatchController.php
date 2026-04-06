<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\StartRoundStopwatch;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class StartRoundStopwatchController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/spustit-stopky-kola/{roundId}',
            'en' => '/en/start-round-stopwatch/{roundId}',
            'es' => '/es/start-round-stopwatch/{roundId}',
            'ja' => '/ja/start-round-stopwatch/{roundId}',
            'fr' => '/fr/start-round-stopwatch/{roundId}',
            'de' => '/de/start-round-stopwatch/{roundId}',
        ],
        name: 'start_round_stopwatch',
        methods: ['POST'],
    )]
    public function __invoke(string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $round->competition->id->toString());

        $this->messageBus->dispatch(new StartRoundStopwatch(roundId: $roundId));

        return $this->redirectToRoute('round_stopwatch', ['roundId' => $roundId]);
    }
}
