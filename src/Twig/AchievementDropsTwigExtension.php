<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Value\AchievementDrops;
use SpeedPuzzling\Web\Value\BadgeType;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `achievement_is_new(type)` - the achievement came with a drop in the last AchievementDrops::NEW_FOR_DAYS days: the
 * "New" tag of the catalogue and the shelf (docs/features/badges.md "Achievement drops")
 */
final class AchievementDropsTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('achievement_is_new', $this->isNew(...)),
        ];
    }

    public function isNew(BadgeType|string $type): bool
    {
        $type = $type instanceof BadgeType ? $type : BadgeType::tryFrom($type);

        return $type !== null && AchievementDrops::isNew($type, $this->clock->now());
    }
}
