<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use SpeedPuzzling\Web\Query\GetEventWizardState;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardPath;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Whether "Continue setting up" (event_wizard_continue → the draft's first step not done) is offered for an event - W2,
 * docs/features/competitions-management/toolkit/W-creation-wizard-research.md §6: a draft (whoever made it, the classic
 * form included) whose path has wizard steps (EventWizardPath::steps() - an edition's or a several-rounds draft's
 * only once W3/W5 build them), for whom the wizard is available and who edits the event. Anything else: no link.
 */
readonly final class EventWizardResume
{
    public function __construct(
        private EventCreationWizardFeature $feature,
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private Security $security,
        private GetEventWizardState $getEventWizardState,
    ) {
    }

    /**
     * From what the caller already has ("You organize": OrganizedEvent) - no statement. The caller knows the viewer
     * edits the event.
     */
    public function offered(bool $isDraft, bool $isEdition, int $roundsCount): bool
    {
        return $isDraft
            && EventWizardPath::of($isEdition, $roundsCount)->steps() !== []
            && $this->feature->isAvailableTo($this->retrieveLoggedUserProfile->getProfile()?->playerId);
    }

    /**
     * Of an event by its id (the draft banner, the edit page): one statement (GetEventWizardState), only when the wizard
     * is available and the viewer edits the event.
     */
    public function offeredFor(string $competitionId): bool
    {
        if ($this->feature->isAvailableTo($this->retrieveLoggedUserProfile->getProfile()?->playerId) === false) {
            return false;
        }

        if ($this->security->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId) === false) {
            return false;
        }

        $state = $this->getEventWizardState->of($competitionId);

        return $state->isDraft && EventWizardPath::of($state->isEdition, $state->roundsCount)->steps() !== [];
    }
}
