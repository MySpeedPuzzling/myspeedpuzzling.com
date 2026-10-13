<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Who may set up online timing (flag ONLINE_ROUNDS_FOR_ORGANISERS, docs/features/feature_flags.md): admins always,
 * organisers only while the flag is on. The single decision point - OnlineRoundsVoter (ONLINE_ROUNDS_MANAGE) adds that
 * the player must be an organiser of the event. Remove with the flag once every organiser may.
 */
readonly final class OnlineRoundsFeature
{
    public function __construct(
        private Security $security,
        private bool $onlineRoundsForOrganisers,
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

        return $this->onlineRoundsForOrganisers || $this->security->isGranted(AdminAccessVoter::ADMIN_ACCESS);
    }
}
