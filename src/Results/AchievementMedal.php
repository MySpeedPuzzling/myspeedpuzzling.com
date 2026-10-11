<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

/**
 * One earned tier as a medal (GetAchievementMedal; the detail sheet and the holders page hero,
 * docs/features/xp-levels/ux-polish/design-spec.md §2 + §8.1).
 *
 * A tier held at launch carries the launch backfill's timestamp, which says nothing about when it was reached: it has
 * no serial and shows no date ("Held at launch"). A tier earned after the launch has both.
 */
readonly final class AchievementMedal
{
    public function __construct(
        public BadgeType $type,
        public BadgeTier $tier,
        public DateTimeImmutable $earnedAt,
        public bool $heldAtLaunch,
        /** Null when held at launch */
        public null|int $serial,
    ) {
    }

    /**
     * The "of N" next to the serial: the cached holder count can lag behind the live serial, never "No. 335 of 334".
     */
    public function totalOf(int $cachedHolders): int
    {
        return max($cachedHolders, $this->serial ?? 0);
    }
}
