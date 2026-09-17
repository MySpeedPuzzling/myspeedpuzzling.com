<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\CompetitionParticipantAlreadyConnectedToDifferentPlayer;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class JoinCompetitionController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionParticipants $getCompetitionParticipants,
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

        if ($request->isMethod('POST')) {
            $participantId = $request->request->getString('participant_id');

            if ($participantId !== '') {
                $this->join($competitionId, $profile->playerId, $participantId);
            } elseif ($request->request->getBoolean('self_join')) {
                $this->join($competitionId, $profile->playerId, null);
            } else {
                // Picker submitted without a name — never fall through to joining under the profile name
                return $this->redirectToRoute('join_competition', ['competitionId' => $competitionId]);
            }

            return $this->redirectToRoute('event_detail', ['slug' => $competition->slug]);
        }

        $isGoing = count($this->getCompetitionParticipants->getPlayerConnections($competitionId, $profile->playerId)) > 0;
        $hasNotConnected = $this->getCompetitionParticipants->hasNotConnectedParticipants($competitionId);

        if ($isGoing === false) {
            // Opted in and their name is on the organizer's list: no need to make them pick it
            $matchingParticipantId = $profile->playerName !== null
                ? $this->getCompetitionParticipants->findNotConnectedParticipantMatchingName($competitionId, $profile->playerName, $profile->country)
                : null;

            if ($matchingParticipantId !== null || $hasNotConnected === false) {
                $this->join($competitionId, $profile->playerId, $matchingParticipantId);

                return $this->redirectToRoute('event_detail', ['slug' => $competition->slug]);
            }
        }

        if ($hasNotConnected === false) {
            // Already going and nobody left on the list to switch to
            return $this->redirectToRoute('event_detail', ['slug' => $competition->slug]);
        }

        return $this->render('join_competition.html.twig', [
            'competition' => $competition,
            'profile' => $profile,
            'profile_country' => CountryCode::fromCode($profile->country),
            'not_connected_participants' => $this->getCompetitionParticipants->getNotConnectedParticipants($competitionId),
            'is_self_joined' => $this->getCompetitionParticipants->isPlayerSelfJoined($competitionId, $profile->playerId),
        ]);
    }

    private function join(string $competitionId, string $playerId, null|string $participantId): void
    {
        try {
            $this->messageBus->dispatch(new JoinCompetition(
                competitionId: $competitionId,
                playerId: $playerId,
                participantId: $participantId,
            ));

            $this->addFlash('success', $this->translator->trans('flashes.competition_join_success'));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof CompetitionParticipantAlreadyConnectedToDifferentPlayer) {
                $this->addFlash('danger', $this->translator->trans('flashes.competition_duplicate_connection'));
            } else {
                throw $e;
            }
        }
    }
}
