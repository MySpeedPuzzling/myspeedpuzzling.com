<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Query\GetRoundTeams;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ManageRoundTeamsController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetRoundTeams $getRoundTeams,
        private readonly GetCompetitionRoundsForManagement $getRoundsForManagement,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/sprava-tymu-kola/{roundId}',
            'en' => '/en/manage-round-teams/{roundId}',
            'es' => '/es/manage-round-teams/{roundId}',
            'ja' => '/ja/manage-round-teams/{roundId}',
            'fr' => '/fr/manage-round-teams/{roundId}',
            'de' => '/de/manage-round-teams/{roundId}',
        ],
        name: 'manage_round_teams',
    )]
    public function __invoke(string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $teams = $this->getRoundTeams->forRound($roundId);
        $unassigned = $this->getRoundTeams->unassignedParticipants($roundId);

        $rounds = $this->getRoundsForManagement->ofCompetition($competitionId);
        $currentRound = null;
        foreach ($rounds as $r) {
            if ($r->id === $roundId) {
                $currentRound = $r;
                break;
            }
        }

        return $this->render('manage_round_teams.html.twig', [
            'round' => $currentRound,
            'roundEntity' => $round,
            'competitionId' => $competitionId,
            'teams' => $teams,
            'unassigned' => $unassigned,
        ]);
    }
}
