<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * One "Is it one of these?" card of the creation wizard's backfill path (GetPastEventMatches): an event (one-time or a
 * date of a series), a series or an organization that may be the one the player is about to add.
 */
readonly final class PastEventMatch
{
    public const string EVENT = 'event';
    public const string SERIES = 'series';
    public const string ORGANIZATION = 'organization';

    /**
     * @param self::EVENT|self::SERIES|self::ORGANIZATION $kind
     */
    public function __construct(
        public string $kind,
        public string $id,
        public string $name,
        public null|string $seriesId,
        public null|string $seriesName,
        public null|DateTimeImmutable $dateFrom,
        public null|DateTimeImmutable $dateTo,
        public null|string $location,
        public null|CountryCode $countryCode,
        public bool $isOnline,
        // Publicly visible - otherwise the viewer's own pending / draft item
        public bool $isPublic,
        public null|string $slug,
        public null|string $seriesSlug,
    ) {
    }
}
