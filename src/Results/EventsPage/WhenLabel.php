<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

/**
 * "Happening now", "Now" (long-running), "Tomorrow", "This weekend", "In 16 days" (soon = coral, ≤ 14 days).
 */
readonly final class WhenLabel
{
    public const string HAPPENING_NOW = 'happening_now';
    public const string NOW = 'now';
    public const string TOMORROW = 'tomorrow';
    public const string THIS_WEEKEND = 'this_weekend';
    public const string IN_DAYS = 'in_days';

    /**
     * @param 'happening_now'|'now'|'tomorrow'|'this_weekend'|'in_days' $type
     */
    public function __construct(
        public string $type,
        public int $days,
        public bool $soon,
    ) {
    }

    public function translationKey(): string
    {
        return 'events_page.when.' . $this->type;
    }
}
