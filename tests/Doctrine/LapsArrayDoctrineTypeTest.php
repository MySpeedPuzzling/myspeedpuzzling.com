<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Doctrine;

use DateTimeImmutable;
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
            '[{"start":"2023-11-07 23:33:20","end":null}]',
        ];

        yield [
            [
                new Lap(new DateTimeImmutable('@1699400000'), new DateTimeImmutable('@1699400010')),
                new Lap(new DateTimeImmutable('@1699400020'), null),
            ],
            '[{"start":"2023-11-07 23:33:20","end":"2023-11-07 23:33:30"},{"start":"2023-11-07 23:33:40","end":null}]',
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
    }
}
