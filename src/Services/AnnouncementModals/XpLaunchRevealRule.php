<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnnouncementModals;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\Xp\XpLaunchReadiness;
use SpeedPuzzling\Web\Value\AnnouncementModal;

/**
 * The XP launch reveal (docs/features/xp-levels/launch-2026-10.md), as a modal the first time a player who logged at
 * least one solving time comes to the site after the launch - for everybody who comes before the launch newsletter or
 * misses it. Players who switched the experience system off are left alone.
 *
 * It is THE launch modal: first in the enum, and the pause between announcement modals does not hold it back
 * (AnnouncementModal::ignoresCooldown()). Opening the reveal page (e.g. from the newsletter link) uses it up -
 * XpLaunchRevealController claims the same impression - so nobody is offered a reveal they have already seen.
 *
 * Not before the launch switch is on (XpLaunchReadiness): until the history is calculated and verified the reveal
 * would show "Level 1", so the modal waits - and nothing is claimed, it simply comes later.
 *
 * No query: `hasSolvingTime` rides on the viewer's own profile row (GetPlayerProfile::byUserId()), the switch on the
 * shared cache.
 */
readonly final class XpLaunchRevealRule implements AnnouncementModalRule
{
    public function __construct(
        private XpLaunchReadiness $xpLaunchReadiness,
    ) {
    }

    public function modal(): AnnouncementModal
    {
        return AnnouncementModal::XpLaunchReveal;
    }

    public function isEligible(PlayerProfile $viewer, DateTimeImmutable $now): bool
    {
        return $viewer->experienceSystemOptedOut === false
            && $viewer->hasSolvingTime
            && $this->xpLaunchReadiness->isReady();
    }
}
