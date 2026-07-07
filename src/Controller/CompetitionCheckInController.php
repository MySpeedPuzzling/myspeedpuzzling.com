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
final class CompetitionCheckInController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/prezence-udalosti/{competitionId}',
            'en' => '/en/event-check-in/{competitionId}',
            'es' => '/es/event-check-in/{competitionId}',
            'ja' => '/ja/event-check-in/{competitionId}',
            'fr' => '/fr/event-check-in/{competitionId}',
            'de' => '/de/event-check-in/{competitionId}',
        ],
        name: 'competition_check_in',
    )]
    public function __invoke(string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);

        return $this->render('competition_check_in.html.twig', [
            'competition' => $competition,
        ]);
    }
}
