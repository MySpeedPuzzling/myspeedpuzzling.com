<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use SpeedPuzzling\Web\Query\GetEventWizardSeries;
use SpeedPuzzling\Web\Query\GetEventWizardState;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardPath;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Whether "Continue setting up" (event_wizard_continue → the draft's first step not done) is offered for an event - W2,
 * docs/features/competitions-management/toolkit/W-creation-wizard-research.md §6: a draft (whoever made it, the classic
 * form included) whose path has wizard steps (EventWizardPath::steps() - a several-rounds draft's only once W5 builds
 * them; an edition never: it is set up by its series' wizard), for whoever edits the event (the wizard is every
 * player's, no flag).
 * A draft series (W3's path) resumes at event_wizard_series_continue - seriesOffered*(). Anything else: no link.
 */
readonly final class EventWizardResume
{
    public function __construct(
        private Security $security,
        private GetEventWizardState $getEventWizardState,
        private GetEventWizardSeries $getEventWizardSeries,
    ) {
    }

    /**
     * From what the caller already has ("You organize": OrganizedEvent) - no statement. The caller knows the viewer
     * edits the event.
     */
    public function offered(bool $isDraft, bool $isEdition, int $roundsCount): bool
    {
        return $isDraft && self::hasSteps($isEdition, $roundsCount);
    }

    /**
     * Of an event by its id (the draft banner, the edit page): one statement (GetEventWizardState), only when the viewer
     * edits the event.
     */
    public function offeredFor(string $competitionId): bool
    {
        if ($this->security->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId) === false) {
            return false;
        }

        $state = $this->getEventWizardState->of($competitionId);

        return $state->isDraft && self::hasSteps($state->isEdition, $state->roundsCount);
    }

    /**
     * A draft series from what the caller already has ("You organize": the item is the viewer's own draft) - no statement
     */
    public function seriesOffered(bool $isDraft): bool
    {
        return $isDraft;
    }

    /**
     * A series by its id (its draft banner): one statement (GetEventWizardSeries), only when the viewer edits the series.
     */
    public function seriesOfferedFor(string $seriesId): bool
    {
        if ($this->security->isGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $seriesId) === false) {
            return false;
        }

        return $this->getEventWizardSeries->state($seriesId)->isDraft;
    }

    /**
     * An edition belongs to its series' wizard - never resumed on its own (EventWizardSteps::of())
     */
    private static function hasSteps(bool $isEdition, int $roundsCount): bool
    {
        return $isEdition === false && EventWizardPath::of($isEdition, $roundsCount)->steps() !== [];
    }
}
