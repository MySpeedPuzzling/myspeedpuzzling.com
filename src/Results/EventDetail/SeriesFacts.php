<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventDetail;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\EventsPage\Place;

/**
 * The series page's facts (header, facts strip, side column): editions, since, coming, next. No "how often" - a guess
 * from dates can be wrong (detail-pages-plan.md, Answers 3).
 */
readonly final class SeriesFacts
{
    public function __construct(
        // distinct competitions, the undated ones too
        public int $editionCount,
        // the first dated session
        public null|DateTimeImmutable $since,
        // live and upcoming sessions - the strip says "N upcoming dates", not editions (an edition may have several)
        public int $comingCount,
        // the Next card's day
        public null|DateTimeImmutable $next,
        public bool $nextIsLive,
        public bool $isOnline,
        public Place $place,
        public null|string $website,
    ) {
    }
}
