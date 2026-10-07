<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\AssignParticipantToTeam;
use SpeedPuzzling\Web\Exceptions\CompetitionTeamNotFound;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AssignParticipantToTeamController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionParticipantRoundRepository $participantRoundRepository,
        private readonly TranslatorInterface $translator,
        private readonly CompetitionTeamRepository $competitionTeamRepository,
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
        $competitionId = $participantRound->round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        if (!$this->isCsrfTokenValid(ManageRoundTeamsController::csrfTokenId($roundId), $request->request->getString('_token'))) {
            $this->addFlash('danger', $this->translator->trans('competition.teams.flash.expired'));

            return $this->redirectToRoute('manage_round_teams', ['roundId' => $roundId], Response::HTTP_SEE_OTHER);
        }

        $teamId = $request->request->getString('team_id');

        // Only a team of this round - the handler refuses others too, this keeps the organiser on the page
        if ($teamId !== '' && $this->isTeamOfRound($teamId, $roundId) === false) {
            $this->addFlash('danger', $this->translator->trans('competition.teams.flash.team_not_in_round'));

            return $this->redirectToRoute('manage_round_teams', ['roundId' => $roundId], Response::HTTP_SEE_OTHER);
        }

        // A pair/team with an official result keeps it - the organiser is told it now belongs to the new line-up
        $moves = ($participantRound->team?->id->toString() ?? '') !== strtolower($teamId);
        $teamsWithResult = $moves && (
            $participantRound->team?->hasOfficialData() === true
            || ($teamId !== '' && $this->competitionTeamRepository->get($teamId)->hasOfficialData())
        );

        $this->messageBus->dispatch(new AssignParticipantToTeam(
            competitionId: $competitionId,
            participantRoundId: $participantRoundId,
            teamId: $teamId !== '' ? $teamId : null,
        ));

        if ($teamsWithResult) {
            $this->addFlash('warning', $this->translator->trans('official_results.guard.team_line_up_changed'));
        }

        return $this->redirectToRoute('manage_round_teams', ['roundId' => $roundId], Response::HTTP_SEE_OTHER);
    }

    private function isTeamOfRound(string $teamId, string $roundId): bool
    {
        try {
            return $this->competitionTeamRepository->get($teamId)->round->id->toString() === $roundId;
        } catch (CompetitionTeamNotFound) {
            return false;
        }
    }
}
