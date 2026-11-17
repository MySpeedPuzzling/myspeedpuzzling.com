<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Who gets the event creation wizard (flag EVENT_CREATION_WIZARD, docs/features/feature_flags.md,
 * docs/features/competitions-management/toolkit/W-creation-wizard-research.md): admins always, every organiser while the
 * flag is on. The single decision point - without it every wizard route answers 404 and "Add event" is the classic form.
 * Remove with the flag once the wizard is everybody's.
 */
readonly final class EventCreationWizardFeature
{
    public function __construct(
        private Security $security,
        private bool $eventCreationWizard,
    ) {
    }

    /**
     * @param null|string $playerId the signed-in viewer's player (null = nobody signed in) - admins are recognised
     *                              through the request's own token (ADMIN_ACCESS)
     */
    public function isAvailableTo(null|string $playerId): bool
    {
        if ($playerId === null) {
            return false;
        }

        return $this->eventCreationWizard || $this->security->isGranted(AdminAccessVoter::ADMIN_ACCESS);
    }
}
