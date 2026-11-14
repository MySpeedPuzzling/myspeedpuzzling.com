<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport\Reading;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\CellInputKind;
use SpeedPuzzling\Web\Value\DataImport\DateOrder;
use SpeedPuzzling\Web\Value\DataImport\DatePrecision;
use SpeedPuzzling\Web\Value\DataImport\DateProblem;
use SpeedPuzzling\Web\Value\DataImport\ImportCalendarWords;
use SpeedPuzzling\Web\Value\DataImport\ImportText;
use SpeedPuzzling\Web\Value\DataImport\ReadDate;

/**
 * Reads one cell of a date column (docs/features/data-import/README.md "Dates", docs/features/data-import/value-readers.md
 * "Dates" - every rule and its examples).
 *
 * A typed date passes through with the precision its format shows. A text is read without guessing: a date written in
 * numbers comes back with every order in which it is a real date (DateOrderResolver settles the column), a month's
 * name or a four-digit year in front has one reading, a text without a year says so, anything else is unreadable.
 * A date after today or before 1950 is read and flagged - a problem to fix, never corrected here. Never throws.
 */
final readonly class ImportDateReader
{
    public const int EARLIEST_YEAR = 1950;

    /** A two-digit year up to this one is 20xx, above it 19xx - fixed, so the same text is the same date in any year */
    public const int LAST_TWO_DIGIT_YEAR_OF_THIS_CENTURY = 69;

    /** A number alone is a year only inside this range ("1000" is a piece count) */
    private const int EARLIEST_BARE_YEAR = 1900;
    private const int LATEST_BARE_YEAR = 2100;

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function read(CellInput $cell): ReadDate
    {
        return match ($cell->kind) {
            CellInputKind::Blank => ReadDate::noDate(),
            CellInputKind::Date => $this->dateCell($cell->asDate(), $cell->precision, $cell->yearKnown),
            CellInputKind::Text => $this->text($cell->asText()),
            CellInputKind::Number => $this->number($cell->asNumber()),
            CellInputKind::Duration, CellInputKind::TimeOfDay, CellInputKind::Boolean => ReadDate::unreadable(),
        };
    }

    /**
     * A date whose year was never seen ("1/3", a cell showing "m/d"), with the year the sheet's name or the player
     * gave. A date that knows its year stays as it is; 29 February of a year without one is unreadable.
     */
    public function withYear(ReadDate $read, int $year): ReadDate
    {
        if ($read->yearKnown || $year < 1 || $year > 9999) {
            return $read;
        }

        if ($read->possibleOrders === []) {
            if ($read->date === null) {
                return $read;
            }

            $reading = $this->reading($year, (int) $read->date->format('n'), (int) $read->date->format('j'), $read->precision);

            return $reading === null
                ? ReadDate::unreadable()
                : ReadDate::certain($reading['date'], $read->precision, true, $reading['problems']);
        }

        $byOrder = [];

        foreach ($read->possibleOrders as $order) {
            $day = $read->dayIn($order);
            $reading = $day === null ? null : $this->reading($year, (int) substr($day, 5, 2), (int) substr($day, 8, 2), DatePrecision::Day);

            if ($reading !== null) {
                $byOrder[$order->value] = $reading;
            }
        }

        return ReadDate::inNumbers($byOrder, true, false);
    }

    private function dateCell(DateTimeImmutable $date, DatePrecision $precision, bool $yearKnown): ReadDate
    {
        $reading = $this->reading(
            (int) $date->format('Y'),
            $precision === DatePrecision::Year ? 1 : (int) $date->format('n'),
            $precision === DatePrecision::Day ? (int) $date->format('j') : 1,
            $precision,
        );

        if ($reading === null) {
            return ReadDate::unreadable();
        }

        return ReadDate::certain($reading['date'], $precision, $yearKnown, $yearKnown ? $reading['problems'] : []);
    }

    private function number(int|float $number): ReadDate
    {
        if (is_float($number)) {
            if (is_nan($number) || is_infinite($number) || floor($number) !== $number || abs($number) > 99_999_999) {
                return ReadDate::unreadable();
            }

            $number = (int) $number;
        }

        return $number < 0 ? ReadDate::unreadable() : $this->text((string) $number);
    }

    private function text(string $text): ReadDate
    {
        if (ImportText::isTooLong($text)) {
            return ReadDate::unreadable();
        }

        $value = ImportText::plain(ImportText::clean($text));

        if ($value === '') {
            return ReadDate::noDate();
        }

        $value = self::withoutWeekday(self::withoutTimeOfDay($value));

        return $this->eastAsian($value)
            ?? $this->numbers($value)
            ?? $this->words($value)
            ?? ReadDate::unreadable();
    }

    /**
     * "2023-12-30 10:00", "2023-12-30T10:00:00Z", "30/12/2023 2:05 pm" - the time after a date says nothing about the day.
     */
    private static function withoutTimeOfDay(string $value): string
    {
        $pattern = '/^(.*\d)(?:\s+|t)\d{1,2}:\d{2}(?::\d{2}(?:[.,]\d+)?)?\s*(?:am|pm|a\.m\.|p\.m\.)?\s*(?:z|utc|gmt|[+-]\d{2}:?\d{2})?$/u';

        return preg_match($pattern, $value, $match) === 1 ? $match[1] : $value;
    }

    /**
     * "Sunday, 3 March 2024", "ne 3. 3. 2024", "2024年3月3日(日)".
     */
    private static function withoutWeekday(string $value): string
    {
        $value = preg_replace('/\s*(?:[(（][月火水木金土日](?:曜日?)?[)）]|[月火水木金土日]曜日?)$/u', '', $value) ?? $value;

        if (preg_match('/^(\p{L}+)[.,]?\s+(.+)$/su', $value, $match) === 1 && in_array($match[1], ImportCalendarWords::WEEKDAYS, true)) {
            return $match[2];
        }

        return $value;
    }

    /**
     * "2024年3月3日", "2024年3月", "2024年", "3月3日", "3月" - the parts are named, so there is one reading.
     */
    private function eastAsian(string $value): null|ReadDate
    {
        if (preg_match('/^(?:(\d{4})年)?\s*(?:(\d{1,2})月)?\s*(?:(\d{1,2})日)?$/u', $value, $match) !== 1) {
            return null;
        }

        $year = ($match[1] ?? '') === '' ? null : (int) $match[1];
        $month = ($match[2] ?? '') === '' ? null : (int) $match[2];
        $day = ($match[3] ?? '') === '' ? null : (int) $match[3];

        return match (true) {
            $month !== null && $day !== null => $this->single($year, $month, $day, DatePrecision::Day),
            $month !== null => $this->single($year, $month, 1, DatePrecision::Month),
            $year !== null && $day === null => $this->single($year, 1, 1, DatePrecision::Year),
            default => ReadDate::unreadable(),
        };
    }

    /**
     * A date written in numbers only.
     */
    private function numbers(string $value): null|ReadDate
    {
        if (preg_match('/^(\d{1,4})\s?([\/.\-])\s?(\d{1,2})\s?\2\s?(\d{1,4})\.?$/', $value, $match) === 1) {
            return $this->threeNumbers($match[1], $match[3], $match[4]);
        }

        if (preg_match('/^(\d{1,4})\s?[\/.\-]\s?(\d{1,4})\.?$/', $value, $match) === 1) {
            return $this->twoNumbers($match[1], $match[2]);
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $match) === 1) {
            // "20231230" - only with a year a bare number may be
            return self::isBareYear((int) $match[1])
                ? $this->single((int) $match[1], (int) $match[2], (int) $match[3], DatePrecision::Day)
                : ReadDate::unreadable();
        }

        if (preg_match('/^\d{4}$/', $value) === 1) {
            return self::isBareYear((int) $value)
                ? $this->single((int) $value, 1, 1, DatePrecision::Year)
                : ReadDate::unreadable();
        }

        return null;
    }

    private function threeNumbers(string $first, string $second, string $third): ReadDate
    {
        // A four-digit year in front: year-month-day, the one order anybody writes that way
        if (strlen($first) === 4) {
            return strlen($third) <= 2
                ? $this->single((int) $first, (int) $second, (int) $third, DatePrecision::Day)
                : ReadDate::unreadable();
        }

        if (strlen($first) > 2) {
            return ReadDate::unreadable();
        }

        $candidates = [];
        $centuryAssumed = false;

        if (strlen($third) === 4) {
            $candidates[DateOrder::DayMonthYear->value] = [(int) $third, (int) $second, (int) $first];
            $candidates[DateOrder::MonthDayYear->value] = [(int) $third, (int) $first, (int) $second];
        } elseif (strlen($third) === 2) {
            $centuryAssumed = true;
            $year = self::fullYear((int) $third);
            $candidates[DateOrder::DayMonthYear->value] = [$year, (int) $second, (int) $first];
            $candidates[DateOrder::MonthDayYear->value] = [$year, (int) $first, (int) $second];

            if (strlen($first) === 2) {
                $candidates[DateOrder::YearMonthDay->value] = [self::fullYear((int) $first), (int) $second, (int) $third];
            }
        }

        return ReadDate::inNumbers($this->readings($candidates), true, $centuryAssumed);
    }

    private function twoNumbers(string $first, string $second): ReadDate
    {
        if (strlen($first) === 4 && strlen($second) <= 2) {
            return $this->single((int) $first, (int) $second, 1, DatePrecision::Month);
        }

        if (strlen($second) === 4 && strlen($first) <= 2) {
            return $this->single((int) $second, (int) $first, 1, DatePrecision::Month);
        }

        if (strlen($first) > 2 || strlen($second) > 2) {
            return ReadDate::unreadable();
        }

        // A day and a month without a year - never a month and a two-digit year
        return ReadDate::inNumbers($this->readings([
            DateOrder::DayMonthYear->value => [null, (int) $second, (int) $first],
            DateOrder::MonthDayYear->value => [null, (int) $first, (int) $second],
            DateOrder::YearMonthDay->value => [null, (int) $first, (int) $second],
        ]), false, false);
    }

    /**
     * A date with a month's name: "3 March 2024", "March 3, 2024", "3. března 2024", "3 de marzo de 2024", "Sept 2020",
     * "Sep-20", "20-Sep", "March".
     */
    private function words(string $value): null|ReadDate
    {
        // "3rd", "1er", "2e", "3º" (which NFKC made "3o")
        $value = preg_replace('/(\d)(?:st|nd|rd|th|er|ere|re|eme|e|o|a)(?![\p{L}\p{N}])/u', '$1', $value) ?? $value;

        if (preg_match_all('/\d+|\p{L}+/u', $value, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        /** @var list<array{type: 'number'|'month', text: string, month: int, before: string}> $tokens */
        $tokens = [];
        $end = 0;

        foreach ($matches[0] as [$text, $offset]) {
            $before = substr($value, $end, $offset - $end);
            $end = $offset + strlen($text);

            if (preg_match('/^[\s,.\-\/\'’]*$/u', $before) !== 1) {
                return null;
            }

            if (ctype_digit($text)) {
                if (strlen($text) > 4) {
                    return null;
                }

                $tokens[] = ['type' => 'number', 'text' => $text, 'month' => 0, 'before' => $before];
            } elseif (isset(ImportCalendarWords::MONTHS[$text])) {
                $tokens[] = ['type' => 'month', 'text' => $text, 'month' => ImportCalendarWords::MONTHS[$text], 'before' => $before];
            } elseif (in_array($text, ImportCalendarWords::FILLERS, true) === false) {
                return null;
            }
        }

        if (preg_match('/^[\s,.]*$/u', substr($value, $end)) !== 1) {
            return null;
        }

        $shape = implode('', array_map(static fn (array $token): string => $token['type'] === 'month' ? 'M' : 'N', $tokens));

        return match ($shape) {
            'NMN' => $this->dayMonthYear($tokens[0]['text'], $tokens[1]['month'], $tokens[2]['text']),
            'MNN' => strlen($tokens[1]['text']) <= 2 ? $this->named($tokens[2]['text'], $tokens[0]['month'], (int) $tokens[1]['text']) : null,
            'MN' => $this->monthThenNumber($tokens[0]['month'], $tokens[1]['text'], $tokens[1]['before']),
            'NM' => strlen($tokens[0]['text']) === 4
                ? $this->single((int) $tokens[0]['text'], $tokens[1]['month'], 1, DatePrecision::Month)
                : $this->named(null, $tokens[1]['month'], (int) $tokens[0]['text']),
            'M' => $this->single(null, $tokens[0]['month'], 1, DatePrecision::Month),
            default => null,
        };
    }

    private function dayMonthYear(string $first, int $month, string $last): null|ReadDate
    {
        // "2024 March 3"
        if (strlen($first) === 4) {
            return strlen($last) <= 2 ? $this->single((int) $first, $month, (int) $last, DatePrecision::Day) : null;
        }

        return strlen($first) <= 2 ? $this->named($last, $month, (int) $first) : null;
    }

    /**
     * A day of a named month, with the year as written (four digits, two, or none).
     */
    private function named(null|string $year, int $month, int $day): null|ReadDate
    {
        if ($year === null) {
            return $this->single(null, $month, $day, DatePrecision::Day);
        }

        return match (strlen($year)) {
            4 => $this->single((int) $year, $month, $day, DatePrecision::Day),
            2 => $this->single(self::fullYear((int) $year), $month, $day, DatePrecision::Day, true),
            default => null,
        };
    }

    /**
     * "Sept 2020" is a month. "Sep-20" is the month of a year the way a spreadsheet shows it; "Sep 20" is a day of
     * September, the year unknown - unless the number is no day of that month.
     */
    private function monthThenNumber(int $month, string $number, string $before): null|ReadDate
    {
        if (strlen($number) === 4) {
            return $this->single((int) $number, $month, 1, DatePrecision::Month);
        }

        if (strlen($number) > 2) {
            return null;
        }

        $asYear = $this->single(self::fullYear((int) $number), $month, 1, DatePrecision::Month, true);

        if (strlen($number) === 2 && preg_match('/[\-\/\'’]/u', $before) === 1) {
            return $asYear;
        }

        $asDay = $this->single(null, $month, (int) $number, DatePrecision::Day);

        return $asDay->date === null && strlen($number) === 2 ? $asYear : $asDay;
    }

    /**
     * A value with one reading - $year null when the text has none.
     */
    private function single(null|int $year, int $month, int $day, DatePrecision $precision, bool $centuryAssumed = false): ReadDate
    {
        $reading = $this->reading($year ?? ReadDate::PLACEHOLDER_YEAR, $month, $day, $precision);

        if ($reading === null) {
            return ReadDate::unreadable();
        }

        return ReadDate::certain($reading['date'], $precision, $year !== null, $year === null ? [] : $reading['problems'], $centuryAssumed);
    }

    /**
     * @param array<string, array{null|int, int, int}> $candidates Year (null = none written), month, day by order
     * @return array<string, array{date: DateTimeImmutable, problems: list<DateProblem>}>
     */
    private function readings(array $candidates): array
    {
        $readings = [];

        foreach ($candidates as $order => [$year, $month, $day]) {
            $reading = $this->reading($year ?? ReadDate::PLACEHOLDER_YEAR, $month, $day, DatePrecision::Day);

            if ($reading !== null) {
                $readings[$order] = $year === null ? ['date' => $reading['date'], 'problems' => []] : $reading;
            }
        }

        return $readings;
    }

    /**
     * @return null|array{date: DateTimeImmutable, problems: list<DateProblem>}
     */
    private function reading(int $year, int $month, int $day, DatePrecision $precision): null|array
    {
        if ($year < 1 || $year > 9999 || checkdate($month, $day, $year) === false) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day), new DateTimeZone('UTC'));

        if ($date === false) {
            return null;
        }

        return ['date' => $date, 'problems' => $this->problems($date, $precision)];
    }

    /**
     * @return list<DateProblem>
     */
    private function problems(DateTimeImmutable $date, DatePrecision $precision): array
    {
        $today = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $problems = [];

        $inTheFuture = match ($precision) {
            // Somewhere it is already tomorrow
            DatePrecision::Day => $date->format('Y-m-d') > $today->modify('+1 day')->format('Y-m-d'),
            DatePrecision::Month => $date->format('Y-m') > $today->format('Y-m'),
            DatePrecision::Year => $date->format('Y') > $today->format('Y'),
        };

        if ($inTheFuture) {
            $problems[] = DateProblem::InTheFuture;
        }

        if ((int) $date->format('Y') < self::EARLIEST_YEAR) {
            $problems[] = DateProblem::Before1950;
        }

        return $problems;
    }

    private static function fullYear(int $twoDigits): int
    {
        return $twoDigits <= self::LAST_TWO_DIGIT_YEAR_OF_THIS_CENTURY ? 2000 + $twoDigits : 1900 + $twoDigits;
    }

    private static function isBareYear(int $year): bool
    {
        return $year >= self::EARLIEST_BARE_YEAR && $year <= self::LATEST_BARE_YEAR;
    }
}
