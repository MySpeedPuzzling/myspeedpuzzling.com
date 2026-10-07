<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Message\RenameCompetitionTeam;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Names (or un-names) one team of one round. Two teams of a round may share a name - the page points it out.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class RenameCompetitionTeamController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionTeamRepository $competitionTeamRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/prejmenovat-tym/{teamId}',
            'en' => '/en/rename-team/{teamId}',
            'es' => '/es/rename-team/{teamId}',
            'ja' => '/ja/rename-team/{teamId}',
            'fr' => '/fr/rename-team/{teamId}',
            'de' => '/de/rename-team/{teamId}',
        ],
        name: 'rename_team',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $teamId): Response
    {
        $team = $this->competitionTeamRepository->get($teamId);
        $roundId = $team->round->id->toString();
        $competitionId = $team->round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $backToTeams = $this->redirect(
            $this->generateUrl('manage_round_teams', ['roundId' => $roundId]) . '#team-' . $team->id->toString(),
            Response::HTTP_SEE_OTHER,
        );

        if (!$this->isCsrfTokenValid(ManageRoundTeamsController::csrfTokenId($roundId), $request->request->getString('_token'))) {
            $this->addFlash('danger', $this->translator->trans('competition.teams.flash.expired'));

            return $backToTeams;
        }

        $name = CompetitionTeam::cleanName($request->request->getString('name'));

        if ($name !== null && mb_strlen($name) > CompetitionTeam::NAME_MAX_LENGTH) {
            $this->addFlash('danger', $this->translator->trans('competition.teams.flash.name_too_long', [
                '%max%' => CompetitionTeam::NAME_MAX_LENGTH,
            ]));

            return $backToTeams;
        }

        $this->messageBus->dispatch(new RenameCompetitionTeam(competitionId: $competitionId, teamId: $team->id->toString(), name: $name));

        $this->addFlash('success', $this->translator->trans(
            $name === null ? 'competition.teams.flash.team_name_removed' : 'competition.teams.flash.team_renamed',
        ));

        return $backToTeams;
    }
}
