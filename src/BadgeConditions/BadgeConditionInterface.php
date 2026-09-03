<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\BadgeConditions;

use SpeedPuzzling\Web\Results\BadgeProgress;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('badge.condition')]
interface BadgeConditionInterface
{
    public function badgeType(): BadgeType;

    /**
     * Returns ALL tiers the player currently qualifies for, ascending.
     *
     * @return list<BadgeTier>
     */
    public function qualifiedTiers(PlayerStatsSnapshot $snapshot): array;

    /**
     * Progress toward the lowest unearned tier. Returns null when the highest tier is already earned,
     * or when the player has no data yet (e.g. never completed a solo 500pc puzzle for the Speed badge).
     */
    public function progressToNextTier(PlayerStatsSnapshot $snapshot, null|BadgeTier $highestEarned): null|BadgeProgress;

    /**
     * Numeric requirement for a given tier, for display on the catalog page.
     * For tiered "speed" badges this is the MAX seconds allowed; for count-based it's the minimum count.
     */
    public function requirementForTier(BadgeTier $tier): int;
}
