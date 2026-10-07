<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\FormData\AddCompetitionTeamsFormData;
use SpeedPuzzling\Web\FormType\AddCompetitionTeamsFormType;
use SpeedPuzzling\Web\Message\CreateCompetitionTeams;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Query\GetRoundTeams;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ManageRoundTeamsController extends AbstractController
{
    /**
     * One token for every form of the page (delete, rename, assign, remove from team) - the assign form is put
     * together by the page's script, which copies it.
     */
    public static function csrfTokenId(string $roundId): string
    {
        return 'manage_round_teams_' . $roundId;
    }

    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetRoundTeams $getRoundTeams,
        private readonly GetCompetitionRoundsForManagement $getRoundsForManagement,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/sprava-tymu-kola/{roundId}',
            'en' => '/en/manage-round-teams/{roundId}',
            'es' => '/es/manage-round-teams/{roundId}',
            'ja' => '/ja/manage-round-teams/{roundId}',
            'fr' => '/fr/manage-round-teams/{roundId}',
            'de' => '/de/manage-round-teams/{roundId}',
        ],
        name: 'manage_round_teams',
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $form = $this->createForm(AddCompetitionTeamsFormType::class, new AddCompetitionTeamsFormData(), [
            'action' => $this->generateUrl('manage_round_teams', ['roundId' => $roundId]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $names = $form->getData()->names()->names;

            $this->messageBus->dispatch(new CreateCompetitionTeams(
                roundId: $roundId,
                names: $names,
            ));

            $this->addFlash('success', $this->translator->trans('competition.teams.flash.teams_created', [
                '%count%' => max(1, count($names)),
            ]));

            return $this->redirectToRoute('manage_round_teams', ['roundId' => $roundId]);
        }

        $teams = $this->getRoundTeams->forRound($roundId);
        $unassigned = $this->getRoundTeams->unassignedParticipants($roundId);

        $rounds = $this->getRoundsForManagement->ofCompetition($competitionId);
        $currentRound = null;
        foreach ($rounds as $r) {
            if ($r->id === $roundId) {
                $currentRound = $r;
                break;
            }
        }

        // Different groups may share a name in one round - each such card says so, so it is never a surprise
        $nameCounts = [];
        foreach ($teams as $team) {
            if ($team->name !== null) {
                $key = mb_strtolower($team->name);
                $nameCounts[$key] = ($nameCounts[$key] ?? 0) + 1;
            }
        }
        $sharedNameTeamIds = [];
        foreach ($teams as $team) {
            if ($team->name !== null && $nameCounts[mb_strtolower($team->name)] > 1) {
                $sharedNameTeamIds[$team->id] = true;
            }
        }

        return $this->render('manage_round_teams.html.twig', [
            'round' => $currentRound,
            'shared_name_team_ids' => $sharedNameTeamIds,
            'csrf_token_id' => self::csrfTokenId($roundId),
            'roundEntity' => $round,
            'competitionId' => $competitionId,
            'teams' => $teams,
            'unassigned' => $unassigned,
            'add_teams_form' => $form,
        ]);
    }
}
