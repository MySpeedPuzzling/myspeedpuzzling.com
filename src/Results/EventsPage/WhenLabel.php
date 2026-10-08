<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

/**
 * "Live" (with the pulsing dot), "Today" (a round later today - rounds only, the events page never says it),
 * "Tomorrow", "This weekend", "In 16 days" (soon = coral, ≤ 14 days).
 */
readonly final class WhenLabel
{
    public const string LIVE = 'live';
    public const string TODAY = 'today';
    public const string TOMORROW = 'tomorrow';
    public const string THIS_WEEKEND = 'this_weekend';
    public const string IN_DAYS = 'in_days';

    /**
     * @param 'live'|'today'|'tomorrow'|'this_weekend'|'in_days' $type
     */
    public function __construct(
        public string $type,
        public int $days,
        public bool $soon,
    ) {
    }

    public function isLive(): bool
    {
        return $this->type === self::LIVE;
    }

    public function translationKey(): string
    {
        return 'events_page.when.' . $this->type;
    }
}
