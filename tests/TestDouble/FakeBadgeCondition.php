<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use SpeedPuzzling\Web\BadgeConditions\BadgeConditionInterface;
use SpeedPuzzling\Web\Results\BadgeProgress;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class FakeBadgeCondition implements BadgeConditionInterface
{
    /**
     * @param list<BadgeTier> $qualifiedTiers
     */
    public function __construct(
        private readonly BadgeType $type,
        private readonly array $qualifiedTiers,
    ) {
    }

    public function badgeType(): BadgeType
    {
        return $this->type;
    }

    public function qualifiedTiers(PlayerStatsSnapshot $snapshot): array
    {
        return $this->qualifiedTiers;
    }

    public function progressToNextTier(PlayerStatsSnapshot $snapshot, null|BadgeTier $highestEarned): null|BadgeProgress
    {
        return null;
    }

    public function requirementForTier(BadgeTier $tier): int
    {
        return 0;
    }
}
