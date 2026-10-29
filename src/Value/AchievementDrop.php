<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * One batch of achievements released together (AchievementDrops, docs/features/badges.md "Achievement drops")
 */
readonly final class AchievementDrop
{
    /**
     * @param list<BadgeType> $types
     */
    public function __construct(
        public DateTimeImmutable $releasedAt,
        public array $types,
    ) {
    }

    public function contains(BadgeType $type): bool
    {
        return in_array($type, $this->types, true);
    }
}
