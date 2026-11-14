<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport\Reading;

use DateTimeImmutable;
use DateTimeZone;
use ErrorException;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use SpeedPuzzling\Web\Services\DataImport\Reading\BarcodeCellReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\DateOrderResolver;
use SpeedPuzzling\Web\Services\DataImport\Reading\DurationColumnResolver;
use SpeedPuzzling\Web\Services\DataImport\Reading\DurationReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\FlagWithDateReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\HeaderMatcher;
use SpeedPuzzling\Web\Services\DataImport\Reading\ImportDateReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\PiecesReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\YesNoReader;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\DateCertainty;
use SpeedPuzzling\Web\Value\DataImport\DatePrecision;
use SpeedPuzzling\Web\Value\DataImport\DurationCertainty;
use SpeedPuzzling\Web\Value\DataImport\DurationColumnCell;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleKey;
use SpeedPuzzling\Web\Value\DataImport\PiecesCertainty;
use SpeedPuzzling\Web\Value\DataImport\ReadDate;
use SpeedPuzzling\Web\Value\DataImport\ReadDuration;
use Symfony\Component\Clock\MockClock;

/**
 * A spreadsheet is somebody's own: any cell may hold anything. Whatever comes in, a reader answers - it never throws,
 * never warns, and what it hands out keeps its promises (a time within the limit, a piece count in range, a real date).
 * The random inputs come from a fixed seed, so a failure can be run again.
 */
final class ValueReadersNeverThrowTest extends TestCase
{
    private const int SEED = 20261114;
    private const int RANDOM_TEXTS = 4000;

    // What time, date, count and flag values are made of - so random texts land near the readers' patterns
    private const array FRAGMENTS = [
        '0', '1', '2', '3', '5', '9', '12', '17', '30', '36', '59', '60', '99', '100', '237', '500', '1000', '2023', '2024',
        ':', ':', ':', '.', ',', '/', '-', '–', ' ', ' ', ' ', '(', ')', '[', ']', '?', '~', '+', '*', 'x', '×', '%', '#', '\'', '"',
        'h', 'm', 's', 'min', 'hr', 'hod', 'Std', 'sec', 'sek', '分', '秒', '時間', '年', '月', '日',
        'Y', 'N', 'yes', 'no', 'ano', 'ne', 'ja', 'oui', 'はい', '✓', '✔️', 'x',
        'Jan', 'March', 'Sept', 'března', 'März', 'marzo', 'août', 'de', 'the', 'of',
        'pcs', 'pieces', 'dílků', 'Teile', 'ピース', 'ish', 'about', 'for both', 'E+12', 'e5', 'NaN', 'INF', '-0',
        "\u{00A0}", "\u{200B}", "\u{200F}", "\u{FEFF}", "\t", "\n", "\u{0000}", '１２', '：', '／', '🧩', 'ž', 'ß', 'İ', "\xC3\x28", "\xFF",
    ];

    protected function setUp(): void
    {
        // A warning or a notice from a reader is a failure here, not a line in a log
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function testNoReaderThrowsOnAnyText(): void
    {
        $durations = new DurationReader();
        $dates = new ImportDateReader(new MockClock(TestClock::now()));
        $pieces = new PiecesReader();
        $yesNo = new YesNoReader();
        $flags = new FlagWithDateReader($yesNo, $dates);
        $barcodes = new BarcodeCellReader();
        $headers = new HeaderMatcher();

        $readDurations = [];
        $readDates = [];

        foreach (self::texts() as $text) {
            $cell = CellInput::text($text);

            $duration = $durations->read($cell);
            $readDurations[] = $duration;
            self::assertDurationKeepsItsPromises($duration, $text);

            $date = $dates->read($cell);
            $readDates[] = $date;
            self::assertDateKeepsItsPromises($date, $text);
            self::assertDateKeepsItsPromises($dates->withYear($date, 2019), $text);

            $count = $pieces->read($cell);
            self::assertSame($count->pieces !== null, in_array($count->certainty, [PiecesCertainty::Certain, PiecesCertainty::ReadFromText], true), $text);
            self::assertSame($count->problem !== null, $count->certainty === PiecesCertainty::Problem, $text);

            if ($count->pieces !== null) {
                self::assertGreaterThanOrEqual(1, $count->pieces, $text);
                self::assertLessThanOrEqual(PiecesReader::MOST_PIECES, $count->pieces, $text);
            }

            $flag = $yesNo->read($cell);
            self::assertSame($flag->value === null, $flag->isYes() === false && $flag->isNo() === false, $text);

            $both = $flags->read($cell);
            self::assertDateKeepsItsPromises($both->date, $text);

            $codes = $barcodes->read($cell);

            foreach ($codes->codes as $code) {
                self::assertMatchesRegularExpression('/^[1-9][0-9]{7,13}$/', $code, $text);
            }

            self::assertSame($codes->codes, $codes->eanList()->codes(), $text);

            foreach ($headers->guess($text) as $guess) {
                self::assertGreaterThanOrEqual(50, $guess->score, $text);
                self::assertLessThanOrEqual(100, $guess->score, $text);
            }

            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', ImportPuzzleKey::of($text, null, null, $text));
        }

        // The column resolvers take whatever the readers made of it
        $random = new Randomizer(new Mt19937(self::SEED));
        $column = array_map(
            static fn (ReadDuration $read): DurationColumnCell => new DurationColumnCell($read, [null, 0, -5, 100, 500, 1000, 60000][$random->getInt(0, 6)]),
            $readDurations,
        );

        foreach ((new DurationColumnResolver())->resolve($column)->decisions as $decision) {
            self::assertGreaterThan(0, $decision->rows);
            self::assertNotSame([], $decision->options);
        }

        $resolution = (new DateOrderResolver())->resolve($readDates);

        foreach ($readDates as $date) {
            self::assertDateKeepsItsPromises($resolution->apply($date), 'after the column\'s order');
        }
    }

    public function testNoReaderThrowsOnAnyNumber(): void
    {
        $durations = new DurationReader();
        $dates = new ImportDateReader(new MockClock(TestClock::now()));
        $pieces = new PiecesReader();
        $yesNo = new YesNoReader();
        $flags = new FlagWithDateReader($yesNo, $dates);
        $barcodes = new BarcodeCellReader();

        $numbers = [
            0, 1, -1, 2, 59, 60, 61, 100, 237, 999, 1000, 1900, 2023, 2100, 2101, 9999, 59999, 60000, 60001, 86399, 86400, 86401,
            3_600_000, 3_600_001, 20231230, 99999999, 4005556169320, 4005560000000, PHP_INT_MAX, PHP_INT_MIN,
            0.0, -0.0, 0.5, 0.048113425925926, 1.5, 8.1, 8.75, 8.999999, 731.78, 1000.0, 2023.0, 1e5, 1e12, 4.00556E+12, 1e15, 1e20, 1e300,
            -0.5, -1e300, 5e-324, 0.1 + 0.2, NAN, INF, -INF, PHP_FLOAT_EPSILON, PHP_FLOAT_MAX, PHP_FLOAT_MIN,
        ];

        $random = new Randomizer(new Mt19937(self::SEED));

        for ($i = 0; $i < 500; $i++) {
            $numbers[] = $random->getInt(-1000, 5_000_000);
            $numbers[] = $random->getInt(-100_000, 100_000_000) / 1000;
        }

        foreach ($numbers as $number) {
            $cell = CellInput::number($number);
            $label = var_export($number, true);

            self::assertDurationKeepsItsPromises($durations->read($cell), $label);
            self::assertDateKeepsItsPromises($dates->read($cell), $label);
            self::assertDateKeepsItsPromises($flags->read($cell)->date, $label);

            $count = $pieces->read($cell);
            self::assertSame($count->pieces !== null, $count->certainty === PiecesCertainty::Certain, $label);
            self::assertNotSame(PiecesCertainty::NoPieces, $count->certainty, $label);

            $yesNo->read($cell);

            foreach ($barcodes->read($cell)->codes as $code) {
                self::assertMatchesRegularExpression('/^[1-9][0-9]{7,13}$/', $code, $label);
            }
        }
    }

    public function testNoReaderThrowsOnAnyTypedCell(): void
    {
        $durations = new DurationReader();
        $dates = new ImportDateReader(new MockClock(TestClock::now()));
        $pieces = new PiecesReader();
        $yesNo = new YesNoReader();
        $flags = new FlagWithDateReader($yesNo, $dates);
        $barcodes = new BarcodeCellReader();

        $cells = [CellInput::blank(), CellInput::boolean(true), CellInput::boolean(false)];

        foreach ([0, 1, 59, 60, 61, 3599, 3600, 3660, 86399, 86400, 86401, 163800, 3_600_000, 3_600_060, PHP_INT_MAX, -1, -60, PHP_INT_MIN] as $seconds) {
            $cells[] = CellInput::duration($seconds);
            $cells[] = CellInput::timeOfDay($seconds);
        }

        foreach (['0001-01-01', '1899-12-30', '1949-12-31', '1950-01-01', '2024-02-29', '2026-10-14', '2026-10-15', '2026-10-16', '9999-12-31'] as $day) {
            foreach (DatePrecision::cases() as $precision) {
                foreach ([true, false] as $yearKnown) {
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
                    self::assertNotFalse($date);
                    $cells[] = CellInput::date($date, $precision, $yearKnown);
                }
            }
        }

        foreach ($cells as $cell) {
            self::assertDurationKeepsItsPromises($durations->read($cell), $cell->kind->value);
            self::assertDateKeepsItsPromises($dates->read($cell), $cell->kind->value);
            self::assertDateKeepsItsPromises($dates->withYear($dates->read($cell), 2023), $cell->kind->value);
            self::assertDateKeepsItsPromises($flags->read($cell)->date, $cell->kind->value);
            self::assertNull($pieces->read($cell)->pieces);
            self::assertSame([], $barcodes->read($cell)->codes);
            $yesNo->read($cell);
        }
    }

    /**
     * @return iterable<string>
     */
    private static function texts(): iterable
    {
        $random = new Randomizer(new Mt19937(self::SEED));

        for ($i = 0; $i < self::RANDOM_TEXTS; $i++) {
            $text = '';

            for ($parts = $random->getInt(0, 8); $parts > 0; $parts--) {
                $text .= self::FRAGMENTS[$random->getInt(0, count(self::FRAGMENTS) - 1)];
            }

            yield $text;
        }

        // Random bytes - mostly no valid text at all
        for ($i = 0; $i < 500; $i++) {
            yield $random->getBytes($random->getInt(1, 40));
        }

        // Long and heavy
        yield str_repeat('1:', 100_000);
        yield str_repeat('9', 2_000_000);
        yield str_repeat(' ', 100_000) . '1:09:17';
        yield str_repeat('(', 5000) . '1:09:17' . str_repeat(')', 5000);
        yield str_repeat('1 h ', 60);
        yield str_repeat('a', 199) . '1';
        yield str_repeat('1,', 100);
        yield str_repeat("\u{200B}", 300);
        yield str_repeat('🧩', 201);
    }

    private static function assertDurationKeepsItsPromises(ReadDuration $read, string $input): void
    {

        foreach ($read->readings as $seconds) {
            self::assertGreaterThan(0, $seconds, $input);
            self::assertLessThanOrEqual(DurationReader::MAX_SECONDS, $seconds, $input);
        }

        if ($read->seconds !== null) {
            self::assertContains($read->seconds, $read->readings, $input);
        }

        switch ($read->certainty) {
            case DurationCertainty::NoTime:
            case DurationCertainty::Unreadable:
                self::assertSame([], $read->readings, $input);
                break;
            case DurationCertainty::Certain:
            case DurationCertainty::ReadFromText:
                self::assertNotNull($read->seconds, $input);
                break;
            case DurationCertainty::ReadWithLeftovers:
                self::assertNotNull($read->leftover, $input);
                self::assertNotSame([], $read->readings, $input);
                break;
            case DurationCertainty::TwoReadings:
                self::assertNotNull($read->shape, $input);
                self::assertNull($read->seconds, $input);
                break;
        }

        self::assertSame($read->alternatives !== [], $read->shape !== null, $input);
    }

    private static function assertDateKeepsItsPromises(ReadDate $read, string $input): void
    {
        if ($read->date !== null) {
            self::assertSame('00:00:00 UTC', $read->date->format('H:i:s e'), $input);
            self::assertSame(DateCertainty::Certain, $read->certainty, $input);
        }

        if ($read->certainty === DateCertainty::Certain) {
            self::assertNotNull($read->date, $input);
        } else {
            self::assertNull($read->date, $input);
        }

        if ($read->certainty === DateCertainty::OrderOpen) {
            self::assertGreaterThan(1, count($read->possibleOrders), $input);
        }

        foreach ($read->possibleOrders as $order) {
            self::assertNotNull($read->dayIn($order), $input);
            self::assertSame(DateCertainty::Certain, $read->inOrder($order)->certainty, $input);
        }
    }
}
