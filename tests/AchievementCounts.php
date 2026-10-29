<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use SpeedPuzzling\Web\Value\BadgeType;

/**
 * How many achievements the pages count - from BadgeType, so a new achievement drop changes no page test
 */
final class AchievementCounts
{
    /** Every achievement a puzzler can earn (the tiered ones) */
    public static function earnable(): int
    {
        return count(array_filter(BadgeType::cases(), static fn (BadgeType $type): bool => $type->isTiered()));
    }

    /** Every achievement, the admin-granted Early Adopter included - the total of its holders */
    public static function all(): int
    {
        return count(BadgeType::cases());
    }

    public static function tiers(): int
    {
        return self::earnable() * 5;
    }
}
