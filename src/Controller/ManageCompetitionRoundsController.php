<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ManageCompetitionRoundsController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/sprava-kol-udalosti/{competitionId}',
            'en' => '/en/manage-event-rounds/{competitionId}',
        ],
        name: 'manage_competition_rounds',
    )]
    public function __invoke(string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);
        $rounds = $this->getCompetitionRoundsForManagement->ofCompetition($competitionId);

        return $this->render('manage_competition_rounds.html.twig', [
            'competition' => $competition,
            'rounds' => $rounds,
        ]);
    }
}
