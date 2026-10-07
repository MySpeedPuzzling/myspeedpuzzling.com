<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ManageRoundResultsController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly GetCompetitionEvents $getCompetitionEvents,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/vysledky-kola/{roundId}',
            'en' => '/en/manage-round-results/{roundId}',
            'es' => '/es/manage-round-results/{roundId}',
            'ja' => '/ja/manage-round-results/{roundId}',
            'fr' => '/fr/manage-round-results/{roundId}',
            'de' => '/de/manage-round-results/{roundId}',
        ],
        name: 'manage_round_results',
    )]
    public function __invoke(string $roundId): Response
    {
        $round = $this->roundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();

        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);

        return $this->render('manage_round_results.html.twig', [
            'round' => $round,
            'competition' => $competition,
        ]);
    }
}
