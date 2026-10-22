<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Types;
use Exception;

/**
 * Replaces DBAL's `datetime_immutable` (the Doctrine cookbook's "working with DateTime instances" pattern). A `timestamp`
 * column has no zone and Doctrine assumes every value is in the default zone: DBAL formats the object in its own zone,
 * so a Europe/Prague object would be stored as Prague wall time and read back as UTC - another instant. Converting to
 * UTC before formatting keeps the instant, so the stored wall time is UTC for any path, including future ones
 * (docs/features/time-and-clock/README.md, R3). A safety net, not the fix: call sites still hand over UTC values (the
 * API v1 processors convert `finishedAt` themselves, so the handler compares the stored day). Hand-formatted SQL
 * parameters (`->format('Y-m-d H:i:s')`) never pass through this type - they stay UTC only because the clock is (R2/R3).
 *
 * `date_immutable` is deliberately left alone: it is a calendar day, and converting a local midnight to UTC would move
 * it to the previous day. `datetimetz_immutable` stores an absolute moment already.
 */
final class UtcDateTimeImmutableDoctrineType extends DateTimeImmutableType
{
    public const string NAME = Types::DATETIME_IMMUTABLE;

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): null|string
    {
        if ($value instanceof DateTimeImmutable) {
            $value = $value->setTimezone(self::utc());
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): null|DateTimeImmutable
    {
        if ($value === null || $value instanceof DateTimeImmutable) {
            return $value;
        }

        assert(is_string($value));

        // The stored wall time is UTC - read it as UTC explicitly instead of relying on PHP's default zone
        $dateTime = DateTimeImmutable::createFromFormat($platform->getDateTimeFormatString(), $value, self::utc());

        if ($dateTime !== false) {
            return $dateTime;
        }

        try {
            return new DateTimeImmutable($value, self::utc());
        } catch (Exception $e) {
            throw InvalidFormat::new($value, self::class, $platform->getDateTimeFormatString(), $e);
        }
    }

    private static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}
