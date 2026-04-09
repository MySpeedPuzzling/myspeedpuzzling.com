<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\CreateCompetitionTeam;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AddTeamToRoundController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pridat-tym-do-kola/{roundId}',
            'en' => '/en/add-team-to-round/{roundId}',
            'es' => '/es/add-team-to-round/{roundId}',
            'ja' => '/ja/add-team-to-round/{roundId}',
            'fr' => '/fr/add-team-to-round/{roundId}',
            'de' => '/de/add-team-to-round/{roundId}',
        ],
        name: 'add_team_to_round',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $round->competition->id->toString());

        $name = $request->request->getString('team_name');

        $this->messageBus->dispatch(new CreateCompetitionTeam(
            teamId: Uuid::uuid7(),
            roundId: $roundId,
            name: $name !== '' ? $name : null,
        ));

        $this->addFlash('success', $this->translator->trans('competition.teams.flash.team_created'));

        return $this->redirectToRoute('manage_round_teams', ['roundId' => $roundId]);
    }
}
