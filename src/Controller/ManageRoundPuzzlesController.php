<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetRoundPuzzlesForManagement;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ManageRoundPuzzlesController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetRoundPuzzlesForManagement $getRoundPuzzlesForManagement,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/sprava-puzzli-kola/{roundId}',
            'en' => '/en/manage-round-puzzles/{roundId}',
        ],
        name: 'manage_round_puzzles',
    )]
    public function __invoke(string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);
        $puzzles = $this->getRoundPuzzlesForManagement->ofRound($roundId);

        return $this->render('manage_round_puzzles.html.twig', [
            'competition' => $competition,
            'round' => $round,
            'puzzles' => $puzzles,
        ]);
    }
}
