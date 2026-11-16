<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ResultsImport;

use DateTimeImmutable;

/**
 * One of the event's own fields the sheet says differently than the stored event.
 */
readonly final class EventFieldChange
{
    public const string NAME = 'name';
    public const string LOCATION = 'location';
    public const string DATE = 'date';
    // 'H:i' or null (no start time known)
    public const string START_TIME = 'start_time';
    // minutes or null (no limit)
    public const string TIME_LIMIT = 'time_limit';

    /**
     * @param self::NAME|self::LOCATION|self::DATE|self::START_TIME|self::TIME_LIMIT $field
     */
    public function __construct(
        public string $field,
        public null|string|int|DateTimeImmutable $before,
        public null|string|int|DateTimeImmutable $after,
    ) {
    }
}
