<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\DeleteCompetitionTeam;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
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
    public function __invoke(string $teamId): Response
    {
        $team = $this->competitionTeamRepository->get($teamId);
        $roundId = $team->round->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $team->round->competition->id->toString());

        $this->messageBus->dispatch(new DeleteCompetitionTeam(teamId: $teamId));

        $this->addFlash('success', $this->translator->trans('competition.teams.flash.team_deleted'));

        return $this->redirectToRoute('manage_round_teams', ['roundId' => $roundId]);
    }
}
