<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\LeaveCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class LeaveCompetitionController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/odhlasit-se-z-udalosti/{competitionId}',
            'en' => '/en/leave-event/{competitionId}',
            'es' => '/es/dejar-evento/{competitionId}',
            'ja' => '/ja/イベント退出/{competitionId}',
            'fr' => '/fr/quitter-evenement/{competitionId}',
            'de' => '/de/event-verlassen/{competitionId}',
        ],
        name: 'leave_competition',
        methods: ['POST'],
    )]
    public function __invoke(string $competitionId): Response
    {
        $competition = $this->getCompetitionEvents->byId($competitionId);
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile !== null) {
            $this->messageBus->dispatch(new LeaveCompetition(
                competitionId: $competitionId,
                playerId: $profile->playerId,
            ));

            $this->addFlash('success', 'flashes.competition_leave_success');
        }

        return $this->redirectToRoute('event_detail', ['slug' => $competition->slug]);
    }
}
