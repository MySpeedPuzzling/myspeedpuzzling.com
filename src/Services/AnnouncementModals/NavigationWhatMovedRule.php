<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnnouncementModals;

use DateTimeImmutable;
use SpeedPuzzling\Web\Navigation\NavigationLaunch;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Value\AnnouncementModal;

/**
 * "What moved" (docs/features/navigation README §8, LAUNCH-01): once the navigation redesign has launched, every
 * player whose account knew the old navigation gets one screen saying where everything is now - once, like every
 * announcement modal. Never while NavigationLaunch::DATE is null, never before the launch day, never for an account
 * created on or after it (it never saw the old menus) nor for a profile without its registration date.
 *
 * It ignores the pause between announcement modals and comes right after the XP launch reveal
 * (AnnouncementModal::ignoresCooldown(), decision 57). No expiry: a player back after months still finds the bars new.
 *
 * No query: the registration date rides on the viewer's profile row, the launch date and the clock come from
 * NavigationLaunch (the one source of "now" for the launch - tests replace it with a MockClock and a date, decision R5;
 * in production it reads the same clock as $now).
 */
readonly final class NavigationWhatMovedRule implements AnnouncementModalRule
{
    public function __construct(
        private NavigationLaunch $launch,
    ) {
    }

    public function modal(): AnnouncementModal
    {
        return AnnouncementModal::NavigationWhatMoved;
    }

    public function isEligible(PlayerProfile $viewer, DateTimeImmutable $now): bool
    {
        if ($this->launch->hasLaunched() === false || $viewer->registeredAt === null) {
            return false;
        }

        return $this->launch->accountPredatesLaunch($viewer->registeredAt);
    }
}
