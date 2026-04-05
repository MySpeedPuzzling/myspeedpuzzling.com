<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\CompetitionParticipantAlreadyConnectedToDifferentPlayer;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class JoinCompetitionController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionParticipants $getCompetitionParticipants,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/prihlasit-se-na-udalost/{competitionId}',
            'en' => '/en/join-event/{competitionId}',
            'es' => '/es/unirse-evento/{competitionId}',
            'ja' => '/ja/イベント参加/{competitionId}',
            'fr' => '/fr/rejoindre-evenement/{competitionId}',
            'de' => '/de/event-beitreten/{competitionId}',
        ],
        name: 'join_competition',
    )]
    public function __invoke(string $competitionId, Request $request): Response
    {
        $competition = $this->getCompetitionEvents->byId($competitionId);
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->redirectToRoute('event_detail', ['slug' => $competition->slug]);
        }

        // POST: Handle form submission
        if ($request->isMethod('POST')) {
            $participantId = $request->request->getString('participant_id');

            try {
                $this->messageBus->dispatch(new JoinCompetition(
                    competitionId: $competitionId,
                    playerId: $profile->playerId,
                    participantId: $participantId !== '' ? $participantId : null,
                ));

                $this->addFlash('success', 'flashes.competition_join_success');
            } catch (HandlerFailedException $e) {
                if ($e->getPrevious() instanceof CompetitionParticipantAlreadyConnectedToDifferentPlayer) {
                    $this->addFlash('danger', 'flashes.competition_duplicate_connection');
                } else {
                    throw $e;
                }
            }

            return $this->redirectToRoute('event_detail', ['slug' => $competition->slug]);
        }

        // GET: Check if we can direct-join or need to show picker
        $notConnected = $this->getCompetitionParticipants->getNotConnectedParticipants($competitionId);
        $existingConnections = $this->getCompetitionParticipants->getPlayerConnections($competitionId, $profile->playerId);

        // If no unconnected participants and not already connected → direct self-join
        if (count($notConnected) === 0 && count($existingConnections) === 0) {
            $this->messageBus->dispatch(new JoinCompetition(
                competitionId: $competitionId,
                playerId: $profile->playerId,
            ));

            $this->addFlash('success', 'flashes.competition_join_success');

            return $this->redirectToRoute('event_detail', ['slug' => $competition->slug]);
        }

        // Build pairing mapping for the picker
        $pairingMapping = $this->getCompetitionParticipants->mappingForPairing($competitionId);

        return $this->render('join_competition.html.twig', [
            'competition' => $competition,
            'profile' => $profile,
            'pairingMapping' => $pairingMapping,
            'hasExistingConnection' => count($existingConnections) > 0,
        ]);
    }
}
