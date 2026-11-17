<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Who may set up the competition toolkit (flag COMPETITION_TOOLKIT_FOR_ORGANISERS, docs/features/feature_flags.md,
 * docs/features/competitions-management/toolkit/R-review-and-plan.md §1.4): admins always, organisers only while the
 * flag is on. The single decision point - CompetitionToolkitVoter (COMPETITION_TOOLKIT_MANAGE) adds that the player
 * must be an organiser of the event. Only settings and editors are gated: what an organiser switched on works for
 * everybody. Remove with the flag once every organiser may.
 */
readonly final class CompetitionToolkitFeature
{
    public function __construct(
        private Security $security,
        private bool $competitionToolkitForOrganisers,
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

        return $this->competitionToolkitForOrganisers || $this->security->isGranted(AdminAccessVoter::ADMIN_ACCESS);
    }
}
