<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use SpeedPuzzling\Web\Query\GetEventWizardSeries;
use SpeedPuzzling\Web\Results\EventWizardProgress;
use SpeedPuzzling\Web\Results\EventWizardSeriesState;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Who may open a page of the wizard's series path (W3) - EventWizardAccess for a series: the feature (404 without it),
 * COMPETITION_SERIES_EDIT (403 otherwise), and a step only while the series is a draft (null = the caller sends the
 * organiser to the series' management page).
 */
readonly final class EventWizardSeriesAccess
{
    public function __construct(
        private EventWizardAccess $access,
        private Security $security,
        private GetEventWizardSeries $getEventWizardSeries,
        private EventWizardSteps $eventWizardSteps,
    ) {
    }

    /**
     * @throws NotFoundHttpException
     * @throws AccessDeniedException
     */
    public function state(string $seriesId): EventWizardSeriesState
    {
        $this->access->player();

        if ($this->security->isGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $seriesId) === false) {
            throw new AccessDeniedException();
        }

        return $this->getEventWizardSeries->state($seriesId);
    }

    /**
     * The draft series' progress when $step is on the series path - null once it is published
     *
     * @throws NotFoundHttpException
     * @throws AccessDeniedException
     */
    public function draft(string $seriesId, EventWizardStep $step): null|EventWizardProgress
    {
        $state = $this->state($seriesId);

        if ($state->isDraft === false) {
            return null;
        }

        $progress = $this->eventWizardSteps->ofSeries($state);

        return $progress->has($step) ? $progress : null;
    }
}
