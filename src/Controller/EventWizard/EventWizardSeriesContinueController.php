<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventWizard;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardSeriesAccess;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardSteps;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Continue setting up" a draft series (W3, the series twin of EventWizardContinueController): the first step of the
 * series path not done yet - derived from the series. A published series goes to its management page.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EventWizardSeriesContinueController extends AbstractController
{
    public function __construct(
        private readonly EventWizardSeriesAccess $seriesAccess,
        private readonly EventWizardSteps $eventWizardSteps,
    ) {
    }

    #[Route(
        path: '/{_locale}/new-event/series/{seriesId}',
        name: 'event_wizard_series_continue',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $seriesId): Response
    {
        $state = $this->seriesAccess->state($seriesId);
        $step = $state->isDraft ? $this->eventWizardSteps->ofSeries($state)->firstNotDone() : null;

        if ($step === null) {
            return $this->redirectToRoute('manage_competition_series', ['seriesId' => $seriesId]);
        }

        return $this->redirectToRoute($step->route(), ['seriesId' => $seriesId]);
    }
}
