<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * "Held at launch" (docs/features/xp-levels/ux-polish/design-spec.md §8.1): a tier that still carries the launch
 * backfill's own timestamp - the backfill ran after the deploy (`xp_settings.full_formula_from`) and switched the
 * launch on at its end (`launch_ready_at`), so a tier earned inside that window has a date and an order that mean
 * nothing: it shows "held at launch" instead of a number and a date, never gets "New" nor "First to earn". The
 * history dating (myspeedpuzzling:date-achievements-from-history) moves every tier it can date out of the window, to
 * the moment the records first met it. Before the launch (no marker) every tier is held at launch.
 * The single-tier Early Adopter keeps its real grant dates: ask this for tiered achievements only.
 */
final readonly class HeldAtLaunch
{
    public function __construct(
        public null|DateTimeImmutable $stampedFrom,
        public null|DateTimeImmutable $launchAt,
    ) {
    }

    public function holds(DateTimeImmutable $earnedAt): bool
    {
        if ($this->launchAt === null) {
            return true;
        }

        return $earnedAt <= $this->launchAt && ($this->stampedFrom === null || $earnedAt >= $this->stampedFrom);
    }
}
