<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * "Held at launch" (docs/features/xp-levels/ux-polish/design-spec.md §8.1): every tiered achievement held when XP
 * launched carries the launch backfill's timestamp, so its date and its order mean nothing. A tier earned at or
 * before `xp_settings.launch_ready_at` (XpLaunchReadiness::readyAt()) - or any tier while the marker is not set - shows
 * "held at launch" instead of a number and a date, never gets "New" nor "First to earn".
 * The single-tier Early Adopter keeps its real grant dates: ask this for tiered achievements only.
 */
final class HeldAtLaunch
{
    public static function is(DateTimeImmutable $earnedAt, null|DateTimeImmutable $launchAt): bool
    {
        return $launchAt === null || $earnedAt <= $launchAt;
    }
}
