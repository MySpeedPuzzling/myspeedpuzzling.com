<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RoundStopwatchController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/stopky-kola/{roundId}',
            'en' => '/en/round-stopwatch/{roundId}',
            'es' => '/es/round-stopwatch/{roundId}',
            'ja' => '/ja/round-stopwatch/{roundId}',
            'fr' => '/fr/round-stopwatch/{roundId}',
            'de' => '/de/round-stopwatch/{roundId}',
        ],
        name: 'round_stopwatch',
    )]
    public function __invoke(string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $canManage = $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $round->competition->id->toString());

        return $this->render('round_stopwatch.html.twig', [
            'round' => $round,
            'competition' => $round->competition,
            'canManage' => $canManage,
            'serverNow' => $this->clock->now()->format(\DateTimeInterface::ATOM),
        ]);
    }
}
