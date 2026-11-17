<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use SpeedPuzzling\Web\Results\EventWizardProgress;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Query\GetEventWizardState;
use SpeedPuzzling\Web\Results\EventWizardState;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Who may open a wizard page, shared by every step's controller:
 *
 * - a signed-in player (every one gets the wizard, no flag) - nobody signed in: every wizard route is a 404;
 * - a draft's steps only for whom COMPETITION_EDIT grants (403 otherwise);
 * - only while the event is a draft on a path whose steps include the page (null = the caller sends the organiser to
 *   the event's edit page - a published event, an edition or an event with several rounds until W3/W5 build theirs).
 */
readonly final class EventWizardAccess
{
    public function __construct(
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private Security $security,
        private GetEventWizardState $getEventWizardState,
        private EventWizardSteps $eventWizardSteps,
    ) {
    }

    /**
     * @throws NotFoundHttpException
     */
    public function player(): PlayerProfile
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            throw new NotFoundHttpException();
        }

        return $player;
    }

    /**
     * @throws NotFoundHttpException
     * @throws AccessDeniedException
     */
    public function state(string $competitionId): EventWizardState
    {
        $this->player();

        if ($this->security->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId) === false) {
            throw new AccessDeniedException();
        }

        return $this->getEventWizardState->of($competitionId);
    }

    /**
     * The draft's progress when $step is one of its path's steps and it is still a draft - null otherwise.
     *
     * @throws NotFoundHttpException
     * @throws AccessDeniedException
     */
    public function draft(string $competitionId, EventWizardStep $step): null|EventWizardProgress
    {
        $state = $this->state($competitionId);

        if ($state->isDraft === false) {
            return null;
        }

        $progress = $this->eventWizardSteps->of($state);

        return $progress->has($step) ? $progress : null;
    }
}
