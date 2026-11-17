<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventWizard;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\CountCompetitionResults;
use SpeedPuzzling\Web\Query\GetWaitingEventLinks;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardAccess;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardSteps;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The backfill path's last page (creation-wizard.md "Backfill"): the event (with "waiting for approval" while it is not
 * public), the rounds made, how many results are linked and how many wait for the approval - all read from the data,
 * nothing carried over. Nothing to submit: the event was submitted when it was created.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EventWizardPastDoneController extends AbstractController
{
    public function __construct(
        private readonly EventWizardAccess $access,
        private readonly EventWizardSteps $eventWizardSteps,
        private readonly CompetitionRepository $competitionRepository,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetWaitingEventLinks $getWaitingEventLinks,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
        private readonly CountCompetitionResults $countCompetitionResults,
    ) {
    }

    #[Route(
        path: '/{_locale}/new-event/{competitionId}/past/done',
        name: 'event_wizard_past_done',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId): Response
    {
        $this->access->player();

        if ($this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId) === false) {
            throw new AccessDeniedException();
        }

        $competition = $this->competitionRepository->get($competitionId);
        $rounds = $this->competitionRoundRepository->ofCompetition($competition);
        return $this->render('event_wizard/past_done.html.twig', [
            'competition' => $competition,
            'rounds' => $rounds,
            'is_public' => $competition->isPubliclyVisible(),
            'linked_count' => $this->countCompetitionResults->forCompetition($competitionId),
            'waiting_count' => $this->getWaitingEventLinks->countFor($competitionId, null),
            'event_url' => $this->competitionDetailUrl->of($competitionId),
            'progress' => $this->eventWizardSteps->forBackfill($competitionId, EventWizardStep::PastDone),
            'step' => EventWizardStep::PastDone,
        ]);
    }
}
