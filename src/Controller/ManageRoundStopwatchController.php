<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ManageRoundStopwatchController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/sprava-stopek-kola/{roundId}',
            'en' => '/en/manage-round-stopwatch/{roundId}',
            'es' => '/es/manage-round-stopwatch/{roundId}',
            'ja' => '/ja/manage-round-stopwatch/{roundId}',
            'fr' => '/fr/manage-round-stopwatch/{roundId}',
            'de' => '/de/manage-round-stopwatch/{roundId}',
        ],
        name: 'manage_round_stopwatch',
    )]
    public function __invoke(string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        return $this->render('manage_round_stopwatch.html.twig', [
            'round' => $round,
            'competition' => $round->competition,
        ]);
    }
}
