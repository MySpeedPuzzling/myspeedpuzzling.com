<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\AssignParticipantToTeam;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AssignParticipantToTeamController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionParticipantRoundRepository $participantRoundRepository,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/priradit-ucastnika-do-tymu/{participantRoundId}',
            'en' => '/en/assign-participant-to-team/{participantRoundId}',
            'es' => '/es/assign-participant-to-team/{participantRoundId}',
            'ja' => '/ja/assign-participant-to-team/{participantRoundId}',
            'fr' => '/fr/assign-participant-to-team/{participantRoundId}',
            'de' => '/de/assign-participant-to-team/{participantRoundId}',
        ],
        name: 'assign_participant_to_team',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $participantRoundId): Response
    {
        $participantRound = $this->participantRoundRepository->get($participantRoundId);
        $roundId = $participantRound->round->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $participantRound->round->competition->id->toString());

        $teamId = $request->request->getString('team_id');

        $this->messageBus->dispatch(new AssignParticipantToTeam(
            participantRoundId: $participantRoundId,
            teamId: $teamId !== '' ? $teamId : null,
        ));

        return $this->redirectToRoute('manage_round_teams', ['roundId' => $roundId]);
    }
}
