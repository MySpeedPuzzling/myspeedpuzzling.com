<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Doctrine\LapsArrayDoctrineType;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\Lap;

class LapsArrayDoctrineTypeTest extends TestCase
{
    /**
     * @param null|array<Lap> $laps
     */
    #[DataProvider('provideConvertToDatabaseValueData')]
    public function testConvertToDatabaseValue(null|array $laps, null|string $expected): void
    {
        $platform = new PostgreSQLPlatform();
        $type = new LapsArrayDoctrineType();

        $actual = $type->convertToDatabaseValue($laps, $platform);

        self::assertEquals($expected, $actual);
    }

    /**
     * @return \Generator<array<mixed>>
     */
    public static function provideConvertToDatabaseValueData(): \Generator
    {
        yield [null, null];

        yield [[], '[]'];

        yield [
            [
                new Lap(new DateTimeImmutable('@1699400000'), null),
            ],
            '[{"start":"2023-11-07 23:33:20.000","end":null}]',
        ];

        yield [
            [
                new Lap(new DateTimeImmutable('@1699400000'), new DateTimeImmutable('@1699400010.987')),
                new Lap(new DateTimeImmutable('@1699400020'), null),
            ],
            '[{"start":"2023-11-07 23:33:20.000","end":"2023-11-07 23:33:30.987"},{"start":"2023-11-07 23:33:40.000","end":null}]',
        ];
    }

    /**
     * @param null|array<Lap> $expected
     */
    #[DataProvider('provideConvertToPHPValueData')]
    public function testConvertToPHPValue(null|string $value, null|array $expected): void
    {
        $platform = new PostgreSQLPlatform();
        $type = new LapsArrayDoctrineType();

        $actual = $type->convertToPHPValue($value, $platform);

        // Non-strict compare
        self::assertEquals($expected, $actual);
    }


    /**
     * @return \Generator<array<mixed>>
     */
    public static function provideConvertToPHPValueData(): \Generator
    {
        yield [null, null];

        yield ['[]', []];

        yield [
            '[{"start":"2023-11-07 23:33:20","end":null}]',
            [
                new Lap(new DateTimeImmutable('@1699400000'), null),
            ],
        ];

        yield [
            '[{"start":"2023-11-07 23:33:20","end":"2023-11-07 23:33:30"},{"start":"2023-11-07 23:33:40","end":null}]',
            [
                new Lap(new DateTimeImmutable('@1699400000'), new DateTimeImmutable('@1699400010')),
                new Lap(new DateTimeImmutable('@1699400020'), null),
            ],
        ];

        // Milliseconds, stored since 2026-10-12
        yield [
            '[{"start":"2023-11-07 23:33:20.950","end":"2023-11-07 23:33:26.937"}]',
            [
                new Lap(new DateTimeImmutable('@1699400000.950'), new DateTimeImmutable('@1699400006.937')),
            ],
        ];
    }

    public function testAMomentInAnotherZoneIsStoredAsTheSameInstantInUtc(): void
    {
        $type = new LapsArrayDoctrineType();
        $prague = new DateTimeZone('Europe/Prague');

        $stored = $type->convertToDatabaseValue([
            new Lap(new DateTimeImmutable('2026-10-20 00:30:00.250', $prague), new DateTimeImmutable('2026-10-20 01:00:00', $prague)),
        ], new PostgreSQLPlatform());

        self::assertSame('[{"start":"2026-10-19 22:30:00.250","end":"2026-10-19 23:00:00.000"}]', $stored);
    }

    public function testMomentsAreReadBackAsUtcWhateverPhpsDefaultZone(): void
    {
        $type = new LapsArrayDoctrineType();
        $defaultZone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $laps = $type->convertToPHPValue('[{"start":"2026-10-19 22:30:00.250","end":"2026-10-19 23:00:00"}]', new PostgreSQLPlatform());
        } finally {
            date_default_timezone_set($defaultZone);
        }

        self::assertIsArray($laps);
        self::assertCount(1, $laps);
        self::assertSame('UTC', $laps[0]->start->getTimezone()->getName());
        self::assertSame('2026-10-19T22:30:00.250+00:00', $laps[0]->start->format('Y-m-d\TH:i:s.vP'));
        self::assertNotNull($laps[0]->end);
        self::assertSame('UTC', $laps[0]->end->getTimezone()->getName());
        self::assertSame(1792450800, $laps[0]->end->getTimestamp());
    }
}
