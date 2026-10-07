<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundTimezone;

readonly final class CompetitionRoundForManagement
{
    public function __construct(
        public string $id,
        public string $name,
        public int $minutesLimit,
        public DateTimeImmutable $startsAt,
        public null|string $badgeBackgroundColor,
        public null|string $badgeTextColor,
        // What the round is shown in on the event pages - see RoundBadgeColor (organiser's colour or palette)
        public string $color,
        public string $textColor,
        // Index in the event's schedule (by start, ties by id) - decides the automatic colour
        public int $schedulePosition,
        public int $puzzleCount,
        public RoundCategory $category = RoundCategory::Solo,
        // The zone startsAt is shown in - see RoundTimezone
        public string $timezone = RoundTimezone::FALLBACK,
        // Nobody said where the event is - $timezone is only the fallback, named without a place (timezone_name())
        public bool $timezoneAssumed = false,
    ) {
    }
}
