<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ManageCompetitionParticipantsController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/sprava-ucastniku-udalosti/{competitionId}',
            'en' => '/en/manage-event-participants/{competitionId}',
            'es' => '/es/manage-event-participants/{competitionId}',
            'ja' => '/ja/manage-event-participants/{competitionId}',
            'fr' => '/fr/manage-event-participants/{competitionId}',
            'de' => '/de/manage-event-participants/{competitionId}',
        ],
        name: 'manage_competition_participants',
    )]
    public function __invoke(string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);

        return $this->render('manage_competition_participants.html.twig', [
            'competition' => $competition,
        ]);
    }
}
