<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventWizard;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardAccess;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardSteps;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Continue setting up" a draft (W2 links it from the draft's banner, "You organize" and the checklist): the first step
 * of its path not done yet (EventWizardSteps) - derived from the draft, nothing stored. A published event, or a draft
 * whose path has no wizard steps yet, goes to its edit page.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EventWizardContinueController extends AbstractController
{
    public function __construct(
        private readonly EventWizardAccess $access,
        private readonly EventWizardSteps $eventWizardSteps,
    ) {
    }

    #[Route(
        path: '/{_locale}/new-event/{competitionId}',
        name: 'event_wizard_continue',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId): Response
    {
        $state = $this->access->state($competitionId);
        $step = $state->isDraft ? $this->eventWizardSteps->of($state)->firstNotDone() : null;

        if ($step === null) {
            return $this->redirectToRoute('edit_competition', ['competitionId' => $competitionId]);
        }

        return $this->redirectToRoute($step->route(), ['competitionId' => $competitionId]);
    }
}
