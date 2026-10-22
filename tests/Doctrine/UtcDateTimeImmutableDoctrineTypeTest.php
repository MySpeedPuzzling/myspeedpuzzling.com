<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Doctrine\UtcDateTimeImmutableDoctrineType;

final class UtcDateTimeImmutableDoctrineTypeTest extends TestCase
{
    public function testAZonedValueIsStoredAsUtcWallTimeAndReadBackAsTheSameInstant(): void
    {
        $type = new UtcDateTimeImmutableDoctrineType();
        $platform = new PostgreSQLPlatform();
        $prague = new DateTimeImmutable('2026-10-20 00:30:00', new DateTimeZone('Europe/Prague'));

        $stored = $type->convertToDatabaseValue($prague, $platform);
        self::assertSame('2026-10-19 22:30:00', $stored);

        $read = $type->convertToPHPValue($stored, $platform);
        self::assertSame($prague->getTimestamp(), $read->getTimestamp());
        self::assertSame('UTC', $read->getTimezone()->getName());
    }

    public function testAnOffsetValueKeepsItsInstant(): void
    {
        $type = new UtcDateTimeImmutableDoctrineType();

        self::assertSame(
            '2026-01-01 05:00:00',
            $type->convertToDatabaseValue(new DateTimeImmutable('2025-12-31T21:00:00-08:00'), new PostgreSQLPlatform()),
        );
    }

    public function testAUtcValueIsStoredUnchanged(): void
    {
        $type = new UtcDateTimeImmutableDoctrineType();
        $utc = new DateTimeImmutable('2026-10-14 12:00:00', new DateTimeZone('UTC'));

        self::assertSame('2026-10-14 12:00:00', $type->convertToDatabaseValue($utc, new PostgreSQLPlatform()));
    }

    public function testTheValuePassedInIsNotChanged(): void
    {
        $type = new UtcDateTimeImmutableDoctrineType();
        $prague = new DateTimeImmutable('2026-10-20 00:30:00', new DateTimeZone('Europe/Prague'));

        $type->convertToDatabaseValue($prague, new PostgreSQLPlatform());

        self::assertSame('Europe/Prague', $prague->getTimezone()->getName());
    }

    public function testNullStaysNull(): void
    {
        $type = new UtcDateTimeImmutableDoctrineType();
        $platform = new PostgreSQLPlatform();

        self::assertNull($type->convertToDatabaseValue(self::nullFromDatabase(), $platform));
        self::assertNull($type->convertToPHPValue(self::nullFromDatabase(), $platform));
    }

    public function testAStoredValueWithFractionsIsReadAsUtc(): void
    {
        $read = new UtcDateTimeImmutableDoctrineType()->convertToPHPValue('2026-10-14 12:00:00.123456', new PostgreSQLPlatform());

        self::assertSame('2026-10-14T12:00:00.123456+00:00', $read->format('Y-m-d\TH:i:s.uP'));
    }

    public function testAnythingElseIsRefusedLikeDbalDoes(): void
    {
        $this->expectException(InvalidType::class);

        new UtcDateTimeImmutableDoctrineType()->convertToDatabaseValue('2026-10-14 12:00:00', new PostgreSQLPlatform());
    }

    /**
     * Typed as what DBAL hands the type, so the assertions are not decided by the type's generics alone
     */
    private static function nullFromDatabase(): mixed
    {
        return null;
    }
}
