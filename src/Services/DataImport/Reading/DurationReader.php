<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport\Reading;

use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\CellInputKind;
use SpeedPuzzling\Web\Value\DataImport\DurationReading;
use SpeedPuzzling\Web\Value\DataImport\DurationReadingRule;
use SpeedPuzzling\Web\Value\DataImport\DurationShape;
use SpeedPuzzling\Web\Value\DataImport\ImportText;
use SpeedPuzzling\Web\Value\DataImport\ReadDuration;

/**
 * Reads one cell of a time column into seconds, with how sure it is (docs/features/data-import/README.md "Durations",
 * docs/features/data-import/value-readers.md "Durations" - every rule and its examples).
 *
 * Never throws and never guesses: a value with one meaning is taken, a shape with several ("17:36", a bare "237", a
 * time-of-day cell without seconds) comes back with every reading for DurationColumnResolver, a time inside other
 * text is only proposed, anything else is unreadable. An empty cell and a zero are "no time" - a result without a
 * time, not a problem.
 */
final readonly class DurationReader
{
    /** 1,000 hours - a reading above it is not offered; no puzzle result is that long */
    public const int MAX_SECONDS = 3_600_000;

    private const int DAY = 86_400;

    private const string NUMBER = '\d{1,5}(?:[.,]\d{1,2})?';

    // Unit words of en / cs / de / es / fr / ja - none has an accent, the case does not matter. A Latin word must end
    // there ("5 stars" is no "5 s"); a Japanese unit may be followed by anything ("23分くらい")
    private const string HOURS = '(?:(?:hours?|hrs?|hodin[ayu]?|hod|stunden?|std|horas?|heures?|h)\.?(?!\p{L})|時間|時)';
    private const string MINUTES = '(?:(?:minutes?|minuten|minut[ayuo]?s?|mins?|mn|m)\.?(?!\p{L})|分間|分|[\'′’](?![\'′’]))';
    private const string SECONDS = '(?:(?:seconds?|secondes?|sekund[ayue]?n?|segundos?|secs?|segs?|sek|s)\.?(?!\p{L})|秒間|秒|"|”|\'\'|′′|’’)';

    // Between two parts: nothing, a comma, or "and" in the six languages
    private const string BETWEEN = '(?:\s*,\s*|\s+(?:and|und|a|y|et)\s+|\s*)';

    private const string CLOCK_3 = '/^(\d{1,4}):(\d{1,2}):(\d{1,2})(?:[.,](\d{1,3}))?$/';
    private const string CLOCK_2 = '/^(\d{1,4}):(\d{1,2})(?:[.,](\d{1,3}))?$/';

    // A clock time inside other text; a decimal comma is not read there ("1:02:03, 58:10" is a list)
    private const string CLOCK_INSIDE = '/(?<![\p{N}:.,])(\d{1,4}):(\d{1,2})(?::(\d{1,2}))?(?:\.(\d{1,3})(?!\d))?(?![\p{N}:])/u';

    // What is left of a value when its one time is taken out must not look like a piece of another time
    private const string BROKEN_TIME = '/\d\s*:|:\s*\d|\?\s*:|:\s*\?/';

    public function read(CellInput $cell): ReadDuration
    {
        return match ($cell->kind) {
            CellInputKind::Blank => ReadDuration::noTime(),
            CellInputKind::Text => $this->text($cell->asText()),
            CellInputKind::Number => $this->number($cell->asNumber()),
            CellInputKind::Duration => $this->durationCell($cell->asSeconds()),
            CellInputKind::TimeOfDay => $this->timeOfDayCell($cell->asSeconds()),
            CellInputKind::Date, CellInputKind::Boolean => ReadDuration::unreadable(),
        };
    }

    private function durationCell(int $seconds): ReadDuration
    {
        if ($seconds === 0) {
            return ReadDuration::noTime();
        }

        if ($seconds < 0) {
            return ReadDuration::unreadable();
        }

        if ($seconds >= self::DAY) {
            return ReadDuration::ambiguous(DurationShape::LongDurationCell, self::storedReadings($seconds));
        }

        if ($seconds % 60 !== 0) {
            return ReadDuration::certain($seconds);
        }

        return ReadDuration::certainUnlessTheColumnSaysOtherwise(
            $seconds,
            DurationShape::ZeroSecondsDurationCell,
            self::storedReadings($seconds),
        );
    }

    private function timeOfDayCell(int $seconds): ReadDuration
    {
        if ($seconds === 0) {
            return ReadDuration::noTime();
        }

        if ($seconds < 0 || $seconds >= self::DAY) {
            return ReadDuration::unreadable();
        }

        // "1:09:17" typed into a plain cell is stored as a time of day - with seconds it has one meaning
        if ($seconds % 60 !== 0) {
            return ReadDuration::certain($seconds);
        }

        return ReadDuration::ambiguous(DurationShape::TimeOfDayCell, self::storedReadings($seconds));
    }

    /**
     * A cell's value as stored, and - when it has no seconds - its hours and minutes taken as minutes and seconds.
     *
     * @return list<DurationReading>
     */
    private static function storedReadings(int $seconds): array
    {
        $readings = [[DurationReadingRule::AsStored, $seconds]];

        if ($seconds % 60 === 0) {
            $readings[] = [DurationReadingRule::StoredHoursAsMinutes, intdiv($seconds, 60)];
        }

        return self::readings($readings);
    }

    private function number(int|float $number): ReadDuration
    {
        if (is_float($number) && (is_nan($number) || is_infinite($number))) {
            return ReadDuration::unreadable();
        }

        if ($number < 0 || $number > self::MAX_SECONDS) {
            return ReadDuration::unreadable();
        }

        if ((float) $number === 0.0) {
            return ReadDuration::noTime();
        }

        if (is_int($number) || floor($number) === $number) {
            return ReadDuration::ambiguous(DurationShape::BareNumber, self::wholeNumberReadings((int) $number));
        }

        $hundredths = round($number * 100, 6);

        // At most two decimals: "8.1" may be 8 h 10 min written the "hours.minutes" way
        if (abs($hundredths - round($hundredths)) < 0.000001) {
            $whole = (int) floor($number);

            return ReadDuration::ambiguous(
                DurationShape::BareNumber,
                self::decimalReadings($number, $whole, (int) round($hundredths) - $whole * 100),
            );
        }

        $readings = [
            [DurationReadingRule::Minutes, (int) round($number * 60)],
            [DurationReadingRule::Hours, (int) round($number * 3600)],
        ];

        if ($number < 1) {
            $readings[] = [DurationReadingRule::DayFraction, (int) round($number * self::DAY)];
        }

        return ReadDuration::ambiguous(DurationShape::BareNumber, self::readings($readings));
    }

    private function text(string $text): ReadDuration
    {
        if (ImportText::isTooLong($text)) {
            return ReadDuration::unreadable();
        }

        // Not lowered and with its accents: what is left around a time goes to the notes as it was typed
        $value = ImportText::clean($text);

        if ($value === '') {
            return ReadDuration::noTime();
        }

        return $this->clockText($value)
            ?? $this->clockWithUnit($value)
            ?? $this->numberText($value)
            ?? $this->unitText($value)
            ?? $this->timeInsideText($value)
            ?? ReadDuration::unreadable();
    }

    /**
     * "1:09:17", "0:56:4", "17:36", "17:36.5" - the whole value is a clock time.
     */
    private function clockText(string $value): null|ReadDuration
    {
        if (preg_match(self::CLOCK_3, $value, $match) === 1) {
            $seconds = self::clock((int) $match[1], (int) $match[2], (int) $match[3]);

            if ($seconds === null) {
                return ReadDuration::unreadable();
            }

            if (($match[4] ?? '') !== '') {
                return self::single(ReadDuration::fromText($seconds + self::roundedUp($match[4])));
            }

            return self::single(ReadDuration::certain($seconds));
        }

        if (preg_match(self::CLOCK_2, $value, $match) !== 1) {
            return null;
        }

        $first = (int) $match[1];
        $second = (int) $match[2];

        if ($second >= 60) {
            return ReadDuration::unreadable();
        }

        // A fraction can only be one of seconds: "17:36.5" is minutes and seconds
        if (($match[3] ?? '') !== '') {
            return self::single(ReadDuration::fromText($first * 60 + $second + self::roundedUp($match[3])));
        }

        if ($first === 0 && $second === 0) {
            return ReadDuration::noTime();
        }

        return ReadDuration::ambiguous(DurationShape::TwoPartText, self::twoPartReadings($first, $second));
    }

    /**
     * "1:02 h" and "17:36 min" say which two parts they are; "1:02:03 h" says nothing new.
     */
    private function clockWithUnit(string $value): null|ReadDuration
    {
        if (preg_match('/^(\d{1,4}):(\d{1,2})(?::(\d{1,2}))?\s*(?:(?<h>' . self::HOURS . ')|(?<m>' . self::MINUTES . '))$/iu', $value, $match) !== 1) {
            return null;
        }

        $first = (int) $match[1];
        $second = (int) $match[2];
        $hours = ($match['h'] ?? '') !== '';

        if (($match[3] ?? '') !== '') {
            $seconds = $hours ? self::clock($first, $second, (int) $match[3]) : null;
        } elseif ($second >= 60) {
            $seconds = null;
        } else {
            $seconds = $hours ? $first * 3600 + $second * 60 : $first * 60 + $second;
        }

        return $seconds === null ? ReadDuration::unreadable() : self::single(ReadDuration::fromText($seconds));
    }

    /**
     * "237", "8.10", "1,5" - a number without a unit has several meanings, the column decides.
     */
    private function numberText(string $value): null|ReadDuration
    {
        if (preg_match('/^(\d{1,7})(?:[.,](\d{1,2}))?$/', $value, $match) !== 1) {
            return null;
        }

        $whole = (int) $match[1];
        $decimals = $match[2] ?? '';

        if ($decimals === '') {
            return $whole === 0
                ? ReadDuration::noTime()
                : ReadDuration::ambiguous(DurationShape::BareNumber, self::wholeNumberReadings($whole));
        }

        $hundredths = (int) str_pad($decimals, 2, '0');

        if ($whole === 0 && $hundredths === 0) {
            return ReadDuration::noTime();
        }

        return ReadDuration::ambiguous(
            DurationShape::BareNumber,
            self::decimalReadings($whole + $hundredths / 100, $whole, $hundredths),
        );
    }

    /**
     * "8min", "1h 23m", "1 h 23 min 5 s", "1h23", "1.5 h", "90 minutes", "1時間23分" - the whole value is numbers with
     * their units.
     */
    private function unitText(string $value): null|ReadDuration
    {
        if (preg_match(self::unitPattern(true), $value, $match) !== 1) {
            return null;
        }

        return self::unitReading($match) ?? ReadDuration::unreadable();
    }

    /**
     * "0:55:41 (slower than last time)", "1:40ish", "casual but 1:11", "about 25 min" - exactly one time inside other
     * text. Several times ("6:07, 6:23, 5:05") or a piece of one ("1:13:??") are unreadable.
     */
    private function timeInsideText(string $value): null|ReadDuration
    {
        $found = [];

        if (preg_match_all(self::CLOCK_INSIDE, $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches as $match) {
                $found[] = [
                    'offset' => $match[0][1],
                    'length' => strlen($match[0][0]),
                    'read' => $this->clockText($match[0][0]) ?? ReadDuration::unreadable(),
                ];
            }
        }

        if (preg_match_all(self::unitPattern(false), $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches as $match) {
                $found[] = [
                    'offset' => $match[0][1],
                    'length' => strlen($match[0][0]),
                    'read' => self::unitReading(array_map(static fn (array $group): string => $group[0], $match)) ?? ReadDuration::unreadable(),
                ];
            }
        }

        if (count($found) !== 1) {
            return null;
        }

        $time = $found[0]['read'];
        $rest = substr($value, 0, $found[0]['offset']) . ' ' . substr($value, $found[0]['offset'] + $found[0]['length']);

        if ($time->readings === [] || preg_match(self::BROKEN_TIME, $rest) === 1) {
            return null;
        }

        $leftover = self::leftover($rest);

        if ($leftover === null) {
            // Only brackets, quotes or a full stop around the time
            return $time->shape === null && $time->seconds !== null ? ReadDuration::fromText($time->seconds) : $time;
        }

        if ($time->shape !== null) {
            return ReadDuration::ambiguous($time->shape, $time->alternatives, $leftover);
        }

        return $time->seconds === null ? null : ReadDuration::withLeftover($time->seconds, $leftover);
    }

    /**
     * The text around a time: separators at its ends and a pair of brackets or quotes around it are not part of it.
     */
    private static function leftover(string $rest): null|string
    {
        $leftover = self::withoutSeparatorsAtTheEnds(preg_replace('/\s+/u', ' ', $rest) ?? '');

        if (preg_match('/^[(\[{"“\'](.*)[)\]}"”\']$/su', $leftover, $match) === 1) {
            $leftover = self::withoutSeparatorsAtTheEnds($match[1]);
        }

        return preg_match('/^[\s.()\[\]{}"“”\']*$/u', $leftover) === 1 ? null : $leftover;
    }

    private static function withoutSeparatorsAtTheEnds(string $text): string
    {
        return preg_replace('/^[\s,;:\-–—·|\/]+|[\s,;:\-–—·|\/]+$/u', '', $text) ?? '';
    }

    /**
     * @param array<int|string, string> $match The groups of unitPattern()
     */
    private static function unitReading(array $match): null|ReadDuration
    {
        $hours = self::part($match, 'h');
        $minutes = self::part($match, 'm') ?? self::part($match, 'm2');
        $seconds = self::part($match, 's') ?? self::part($match, 's2') ?? self::part($match, 's3');
        $tail = self::part($match, 'tail');

        // "1h23" - the number after the last unit is the next smaller one
        if ($tail !== null) {
            if ($seconds !== null) {
                return null;
            }

            if ($minutes !== null) {
                $seconds = $tail;
            } else {
                $minutes = $tail;
            }
        }

        $parts = array_filter([$hours, $minutes, $seconds], static fn (null|string $part): bool => $part !== null);
        $decimal = array_filter($parts, static fn (string $part): bool => ctype_digit($part) === false);

        if ($decimal !== []) {
            return count($parts) === 1 ? self::decimalWithUnit($hours, $minutes, $seconds) : null;
        }

        // Under a larger unit a smaller one stays below 60: "1h 75m" is not a time
        if (($hours !== null && $minutes !== null && (int) $minutes >= 60) || (($hours !== null || $minutes !== null) && $seconds !== null && (int) $seconds >= 60)) {
            return null;
        }

        $total = (int) $hours * 3600 + (int) $minutes * 60 + (int) $seconds;

        if ($total === 0) {
            return ReadDuration::noTime();
        }

        return $total > self::MAX_SECONDS ? null : ReadDuration::fromText($total);
    }

    /**
     * "1.5 h" is one and a half hours. With two decimals below 60 ("1.30 h") it may be the "hours.minutes" way of
     * writing - both readings, the column decides.
     */
    private static function decimalWithUnit(null|string $hours, null|string $minutes, null|string $seconds): null|ReadDuration
    {
        $number = $hours ?? $minutes ?? $seconds ?? '';
        [$whole, $decimals] = explode('.', str_replace(',', '.', $number)) + [1 => ''];
        $value = (int) $whole + (int) str_pad($decimals, 2, '0') / 100;

        $unit = match (true) {
            $hours !== null => 3600,
            $minutes !== null => 60,
            default => 1,
        };

        $decimalSeconds = (int) round($value * $unit);

        if ($decimalSeconds === 0) {
            return ReadDuration::noTime();
        }

        if ($decimalSeconds > self::MAX_SECONDS) {
            return null;
        }

        if ($unit === 1 || strlen($decimals) !== 2 || (int) $decimals >= 60) {
            return ReadDuration::fromText($decimalSeconds);
        }

        return ReadDuration::ambiguous(DurationShape::DecimalWithUnit, self::readings($unit === 3600 ? [
            [DurationReadingRule::Hours, $decimalSeconds],
            [DurationReadingRule::HoursDotMinutes, (int) $whole * 3600 + (int) $decimals * 60],
        ] : [
            [DurationReadingRule::Minutes, $decimalSeconds],
            [DurationReadingRule::MinutesDotSeconds, (int) $whole * 60 + (int) $decimals],
        ]));
    }

    /**
     * @param array<int|string, string> $match
     */
    private static function part(array $match, string $name): null|string
    {
        return ($match[$name] ?? '') === '' ? null : $match[$name];
    }

    /**
     * Numbers with their units, largest first. Anchored: the whole value, a last number may stand alone after a space
     * ("1 h 23"). Inside other text a last number must be attached ("1h23 with Ana"; "8 min 2 attempts" is 8 minutes).
     */
    private static function unitPattern(bool $anchored): string
    {
        $number = self::NUMBER;
        $between = self::BETWEEN;

        $body = '(?:'
            . '(?<h>' . $number . ')\s*' . self::HOURS . '(?:' . $between . '(?<m>' . $number . ')\s*' . self::MINUTES . ')?(?:' . $between . '(?<s>' . $number . ')\s*' . self::SECONDS . ')?'
            . '|(?<m2>' . $number . ')\s*' . self::MINUTES . '(?:' . $between . '(?<s2>' . $number . ')\s*' . self::SECONDS . ')?'
            . '|(?<s3>' . $number . ')\s*' . self::SECONDS
            . ')';

        return $anchored
            ? '/^' . $body . '(?:\s*(?<tail>\d{1,2}))?$/iu'
            : '/(?<![\p{L}\p{N}:.,\'"′’”])' . $body . '(?:(?<tail>\d{1,2})(?![\p{L}\p{N}:.,])|(?!\d))/iu';
    }

    /**
     * @return list<DurationReading>
     */
    private static function twoPartReadings(int $first, int $second): array
    {
        return self::readings([
            [DurationReadingRule::MinutesSeconds, $first * 60 + $second],
            [DurationReadingRule::HoursMinutes, $first * 3600 + $second * 60],
        ]);
    }

    /**
     * @return list<DurationReading>
     */
    private static function wholeNumberReadings(int $number): array
    {
        return self::readings([
            [DurationReadingRule::Minutes, $number * 60],
            [DurationReadingRule::Seconds, $number],
            [DurationReadingRule::Hours, $number * 3600],
            [DurationReadingRule::HoursDotMinutes, $number * 3600],
            [DurationReadingRule::MinutesDotSeconds, $number * 60],
        ]);
    }

    /**
     * @return list<DurationReading>
     */
    private static function decimalReadings(float $value, int $whole, int $hundredths): array
    {
        $readings = [
            [DurationReadingRule::Minutes, (int) round($value * 60)],
            [DurationReadingRule::Hours, (int) round($value * 3600)],
        ];

        if ($hundredths < 60) {
            $readings[] = [DurationReadingRule::HoursDotMinutes, $whole * 3600 + $hundredths * 60];
            $readings[] = [DurationReadingRule::MinutesDotSeconds, $whole * 60 + $hundredths];
        }

        return self::readings($readings);
    }

    /**
     * The readings that are a time: above zero, at most MAX_SECONDS.
     *
     * @param list<array{DurationReadingRule, int}> $readings
     * @return list<DurationReading>
     */
    private static function readings(array $readings): array
    {
        $kept = [];

        foreach ($readings as [$rule, $seconds]) {
            if ($seconds > 0 && $seconds <= self::MAX_SECONDS) {
                $kept[] = new DurationReading($rule, $seconds);
            }
        }

        return $kept;
    }

    private static function clock(int $hours, int $minutes, int $seconds): null|int
    {
        if ($minutes >= 60 || $seconds >= 60) {
            return null;
        }

        return $hours * 3600 + $minutes * 60 + $seconds;
    }

    /**
     * A fraction of a second, rounded half up to 0 or 1.
     */
    private static function roundedUp(string $fraction): int
    {
        return (int) substr($fraction, 0, 1) >= 5 ? 1 : 0;
    }

    /**
     * A reading with one meaning: zero is "no time", above MAX_SECONDS no time at all.
     */
    private static function single(ReadDuration $read): ReadDuration
    {
        return match (true) {
            $read->seconds === null => $read,
            $read->seconds === 0 => ReadDuration::noTime(),
            $read->seconds > self::MAX_SECONDS => ReadDuration::unreadable(),
            default => $read,
        };
    }
}
