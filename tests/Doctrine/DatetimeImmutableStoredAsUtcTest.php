<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the registered `datetime_immutable` does since it is DBAL's datetime_utc_immutable (config/packages/doctrine.php,
 * docs/features/time-and-clock/README.md R3) - pinned here so a config change cannot quietly drop the UTC conversion
 */
final class DatetimeImmutableStoredAsUtcTest extends KernelTestCase
{
    public function testAZonedValueIsStoredAsUtcWallTimeAndReadBackAsTheSameInstant(): void
    {
        $type = self::type();
        $platform = new PostgreSQLPlatform();
        $prague = new DateTimeImmutable('2026-10-20 00:30:00', new DateTimeZone('Europe/Prague'));

        $stored = $type->convertToDatabaseValue($prague, $platform);
        self::assertSame('2026-10-19 22:30:00', $stored);

        $read = $type->convertToPHPValue($stored, $platform);
        self::assertInstanceOf(DateTimeImmutable::class, $read);
        self::assertSame($prague->getTimestamp(), $read->getTimestamp());
        self::assertSame('UTC', $read->getTimezone()->getName());
    }

    public function testAnOffsetValueKeepsItsInstant(): void
    {
        $type = self::type();

        self::assertSame(
            '2026-01-01 05:00:00',
            $type->convertToDatabaseValue(new DateTimeImmutable('2025-12-31T21:00:00-08:00'), new PostgreSQLPlatform()),
        );
    }

    public function testAUtcValueIsStoredUnchanged(): void
    {
        $type = self::type();
        $utc = new DateTimeImmutable('2026-10-14 12:00:00', new DateTimeZone('UTC'));

        self::assertSame('2026-10-14 12:00:00', $type->convertToDatabaseValue($utc, new PostgreSQLPlatform()));
    }

    public function testTheValuePassedInIsNotChanged(): void
    {
        $type = self::type();
        $prague = new DateTimeImmutable('2026-10-20 00:30:00', new DateTimeZone('Europe/Prague'));

        $type->convertToDatabaseValue($prague, new PostgreSQLPlatform());

        self::assertSame('Europe/Prague', $prague->getTimezone()->getName());
    }

    public function testNullStaysNull(): void
    {
        $type = self::type();
        $platform = new PostgreSQLPlatform();

        self::assertNull($type->convertToDatabaseValue(self::nullFromDatabase(), $platform));
        self::assertNull($type->convertToPHPValue(self::nullFromDatabase(), $platform));
    }

    public function testAStoredValueWithFractionsIsReadAsUtc(): void
    {
        $read = self::type()->convertToPHPValue('2026-10-14 12:00:00.123456', new PostgreSQLPlatform());
        self::assertInstanceOf(DateTimeImmutable::class, $read);

        self::assertSame('2026-10-14T12:00:00.123456+00:00', $read->format('Y-m-d\TH:i:s.uP'));
    }

    public function testAnythingElseIsRefusedLikeDbalDoes(): void
    {
        $this->expectException(InvalidType::class);

        self::type()->convertToDatabaseValue('2026-10-14 12:00:00', new PostgreSQLPlatform());
    }

    private static function type(): Type
    {
        // The kernel registers the configured types
        self::bootKernel();

        return Type::getType(Types::DATETIME_IMMUTABLE);
    }

    /**
     * Typed as what DBAL hands the type, so the assertions are not decided by the type's generics alone
     */
    private static function nullFromDatabase(): mixed
    {
        return null;
    }
}
