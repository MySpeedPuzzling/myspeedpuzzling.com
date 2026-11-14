<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value\DataImport;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\CellInputKind;
use SpeedPuzzling\Web\Value\DataImport\DatePrecision;

final class CellInputTest extends TestCase
{
    public function testEveryKindCarriesItsValue(): void
    {
        self::assertSame(CellInputKind::Blank, CellInput::blank()->kind);

        $text = CellInput::text(' 1:09:17 ');
        self::assertSame(CellInputKind::Text, $text->kind);
        self::assertSame(' 1:09:17 ', $text->asText(), 'As typed - the readers clean it');

        self::assertSame(1000, CellInput::number(1000)->asNumber());
        self::assertSame(8.1, CellInput::number(8.1)->asNumber());

        $duration = CellInput::duration(4157);
        self::assertSame(CellInputKind::Duration, $duration->kind);
        self::assertSame(4157, $duration->asSeconds());

        $timeOfDay = CellInput::timeOfDay(3720);
        self::assertSame(CellInputKind::TimeOfDay, $timeOfDay->kind);
        self::assertSame(3720, $timeOfDay->asSeconds());

        self::assertTrue(CellInput::boolean(true)->asBoolean());
        self::assertFalse(CellInput::boolean(false)->asBoolean());
    }

    public function testADateIsItsCalendarDayAtMidnightUtc(): void
    {
        $cell = CellInput::date(new DateTimeImmutable('2025-01-03 23:45:00', new DateTimeZone('America/Los_Angeles')));

        self::assertSame(CellInputKind::Date, $cell->kind);
        self::assertSame('2025-01-03 00:00:00 UTC', $cell->asDate()->format('Y-m-d H:i:s e'));
        self::assertSame(DatePrecision::Day, $cell->precision);
        self::assertTrue($cell->yearKnown);
    }

    public function testADateCarriesWhatItsFormatShows(): void
    {
        $cell = CellInput::date(new DateTimeImmutable('2020-09-14', new DateTimeZone('UTC')), DatePrecision::Month, false);

        self::assertSame(DatePrecision::Month, $cell->precision);
        self::assertFalse($cell->yearKnown);
        self::assertSame('2020-09-14', $cell->asDate()->format('Y-m-d'), 'The reader, not the cell, cuts a month down to its first day');
    }

    public function testAskingForAnotherKindIsAProgrammingError(): void
    {
        $wrong = [
            static fn (): string => CellInput::number(5)->asText(),
            static fn (): int|float => CellInput::text('5')->asNumber(),
            static fn (): int => CellInput::number(5)->asSeconds(),
            static fn (): DateTimeImmutable => CellInput::text('2023-12-30')->asDate(),
            static fn (): bool => CellInput::number(1)->asBoolean(),
            static fn (): string => CellInput::blank()->asText(),
        ];

        foreach ($wrong as $ask) {
            try {
                $ask();
                self::fail('Expected a LogicException');
            } catch (LogicException $exception) {
                self::assertStringContainsString('cell is not', $exception->getMessage());
            }
        }
    }
}
