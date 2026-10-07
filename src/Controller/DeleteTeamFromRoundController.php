<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\OfficialResultsProtected;
use SpeedPuzzling\Web\Message\DeleteCompetitionTeam;
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
final class DeleteTeamFromRoundController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionTeamRepository $competitionTeamRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/smazat-tym/{teamId}',
            'en' => '/en/delete-team/{teamId}',
            'es' => '/es/delete-team/{teamId}',
            'ja' => '/ja/delete-team/{teamId}',
            'fr' => '/fr/delete-team/{teamId}',
            'de' => '/de/delete-team/{teamId}',
        ],
        name: 'delete_team',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $teamId): Response
    {
        $team = $this->competitionTeamRepository->get($teamId);
        $roundId = $team->round->id->toString();
        $competitionId = $team->round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        if (!$this->isCsrfTokenValid(ManageRoundTeamsController::csrfTokenId($roundId), $request->request->getString('_token'))) {
            $this->addFlash('danger', $this->translator->trans('competition.teams.flash.expired'));

            return $this->redirectToRoute('manage_round_teams', ['roundId' => $roundId], Response::HTTP_SEE_OTHER);
        }

        // Its members go back to "unassigned" in this round, nothing else about them changes
        try {
            $this->messageBus->dispatch(new DeleteCompetitionTeam(competitionId: $competitionId, teamId: $teamId));
        } catch (OfficialResultsProtected $protected) {
            $this->addFlash('danger', $this->translator->trans($protected->translationKey()));

            return $this->redirectToRoute('manage_round_teams', ['roundId' => $roundId], Response::HTTP_SEE_OTHER);
        }

        $this->addFlash('success', $this->translator->trans('competition.teams.flash.team_deleted'));

        return $this->redirectToRoute('manage_round_teams', ['roundId' => $roundId], Response::HTTP_SEE_OTHER);
    }
}
