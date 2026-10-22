<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\DateTimeUtcImmutableType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * R3 of docs/features/time-and-clock/README.md: UTC in the database, a zone only on output.
 */
final class TimezoneConfigurationTest extends KernelTestCase
{
    /**
     * Pinned by the base image's ini (`date.timezone = "UTC"` in ghcr.io/myspeedpuzzling/web-base-php85), not by
     * application code - this test notices if an image or CI job runs PHP without it
     */
    public function testPhpDefaultTimezoneIsUtc(): void
    {
        self::assertSame('UTC', date_default_timezone_get());
    }

    public function testDatetimeImmutableColumnsAreWrittenInUtc(): void
    {
        // Booting the connection registers the configured types
        self::getContainer()->get(Connection::class);

        self::assertInstanceOf(DateTimeUtcImmutableType::class, Type::getType(Types::DATETIME_IMMUTABLE));
    }
}
