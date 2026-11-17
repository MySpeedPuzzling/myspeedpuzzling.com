<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetCompetitionAdvancementSteps;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Results\AdvancementStepsView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The declared advancement steps of an event (competition toolkit A8, A-round-formats.md §3.5) - the "spider": which
 * rounds feed which, how many qualify, how they are spread; the stage of each round. A step that a later round change
 * broke is marked for the organiser. Only for whom COMPETITION_TOOLKIT_MANAGE grants.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AdvancementStepsController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private readonly GetCompetitionAdvancementSteps $getCompetitionAdvancementSteps,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/postup-udalosti/{competitionId}',
            'en' => '/en/manage-event-advancement/{competitionId}',
            'es' => '/es/manage-event-advancement/{competitionId}',
            'ja' => '/ja/manage-event-advancement/{competitionId}',
            'fr' => '/fr/manage-event-advancement/{competitionId}',
            'de' => '/de/manage-event-advancement/{competitionId}',
        ],
        name: 'competition_advancement_steps_manage',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);
        $rounds = $this->getCompetitionRoundsForManagement->ofCompetition($competitionId);
        $steps = $this->getCompetitionAdvancementSteps->ofCompetition($competitionId);

        $response = $this->render('competition_toolkit/advancement_steps.html.twig', [
            'competition' => $competition,
            'rounds' => $rounds,
            'view' => AdvancementStepsView::of($steps, $rounds),
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
