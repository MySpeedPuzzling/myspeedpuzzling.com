<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\CompetitionParticipantAlreadyConnectedToDifferentPlayer;
use SpeedPuzzling\Web\Exceptions\RegistrationNotOpen;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Query\GetClaimableResultsForPlayer;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\GetRoundTeams;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class JoinCompetitionController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionParticipants $getCompetitionParticipants,
        private readonly GetRoundTeams $getRoundTeams,
        private readonly GetClaimableResultsForPlayer $getClaimableResults,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
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
            $teamId = $request->request->getString('team_id');

            try {
                $this->messageBus->dispatch(new JoinCompetition(
                    competitionId: $competitionId,
                    playerId: $profile->playerId,
                    participantId: $participantId !== '' ? $participantId : null,
                    teamId: $teamId !== '' ? $teamId : null,
                ));

                $this->addFlash('success', $this->translator->trans('flashes.competition_join_success'));
            } catch (HandlerFailedException $e) {
                if ($e->getPrevious() instanceof CompetitionParticipantAlreadyConnectedToDifferentPlayer) {
                    $this->addFlash('danger', $this->translator->trans('flashes.competition_duplicate_connection'));
                } elseif ($e->getPrevious() instanceof RegistrationNotOpen) {
                    $this->addFlash('danger', $this->translator->trans('flashes.competition_registration_not_open'));
                } else {
                    throw $e;
                }

                return $this->redirectToRoute('event_detail', ['slug' => $competition->slug]);
            }

            // Newly connected identity may have claimable results — offer them right away
            if ($this->getClaimableResults->inCompetition($competitionId, $profile->playerId) !== []) {
                return $this->redirectToRoute('claim_results', ['competitionId' => $competitionId]);
            }

            return $this->redirectToRoute('event_detail', ['slug' => $competition->slug]);
        }

        // GET: Check if we can direct-join or need to show picker
        $notConnected = $this->getCompetitionParticipants->getNotConnectedParticipants($competitionId);
        $existingConnections = $this->getCompetitionParticipants->getPlayerConnections($competitionId, $profile->playerId);
        $teams = $this->getRoundTeams->teamsForCompetition($competitionId);

        // If no unconnected participants, no teams to pick and not already connected → direct self-join
        if (count($notConnected) === 0 && count($teams) === 0 && count($existingConnections) === 0) {
            try {
                $this->messageBus->dispatch(new JoinCompetition(
                    competitionId: $competitionId,
                    playerId: $profile->playerId,
                ));

                $this->addFlash('success', $this->translator->trans('flashes.competition_join_success'));
            } catch (HandlerFailedException $e) {
                if ($e->getPrevious() instanceof RegistrationNotOpen) {
                    $this->addFlash('danger', $this->translator->trans('flashes.competition_registration_not_open'));
                } else {
                    throw $e;
                }
            }

            return $this->redirectToRoute('event_detail', ['slug' => $competition->slug]);
        }

        // Build pairing mapping for the picker
        $pairingMapping = $this->getCompetitionParticipants->mappingForPairing($competitionId);

        return $this->render('join_competition.html.twig', [
            'competition' => $competition,
            'profile' => $profile,
            'pairingMapping' => $pairingMapping,
            'teams' => $teams,
            'hasExistingConnection' => count($existingConnections) > 0,
        ]);
    }
}
