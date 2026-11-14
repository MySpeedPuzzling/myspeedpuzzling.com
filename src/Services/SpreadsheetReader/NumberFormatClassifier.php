<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader;

use SpeedPuzzling\Web\Value\Spreadsheet\DatePrecision;
use SpeedPuzzling\Web\Value\Spreadsheet\NumberFormat;
use SpeedPuzzling\Web\Value\Spreadsheet\NumberFormatKind;

/**
 * What a spreadsheet number format code makes of a number: a date and how exactly it shows it, a time of day, an
 * elapsed time, or a plain number (docs/features/data-import/reader.md "Number formats").
 *
 * Only the first section of a code counts (the one for positive numbers). Quoted text, escaped characters, colours,
 * conditions and locale tags are literals; [h], [m], [s] mean elapsed time; an "m" right after hours or right before
 * seconds is minutes, any other "m" a month.
 */
final class NumberFormatClassifier
{
    /**
     * The formats a workbook refers to by number without spelling them out (ECMA-376 §18.8.30). 14 is the "short date"
     * of the reader's own country - a full date with the year everywhere. 27-36 and 50-58 are dates in East Asian
     * versions (two of them times in one version, a month in another): a full date is the reading that loses nothing.
     */
    private const array BUILT_IN = [
        0 => 'General',
        1 => '0',
        2 => '0.00',
        3 => '#,##0',
        4 => '#,##0.00',
        5 => '#,##0',
        6 => '#,##0',
        7 => '#,##0.00',
        8 => '#,##0.00',
        9 => '0%',
        10 => '0.00%',
        11 => '0.00E+00',
        12 => '# ?/?',
        13 => '# ??/??',
        14 => 'm/d/yyyy',
        15 => 'd-mmm-yy',
        16 => 'd-mmm',
        17 => 'mmm-yy',
        18 => 'h:mm AM/PM',
        19 => 'h:mm:ss AM/PM',
        20 => 'h:mm',
        21 => 'h:mm:ss',
        22 => 'm/d/yyyy h:mm',
        27 => 'yyyy/m/d',
        28 => 'yyyy/m/d',
        29 => 'yyyy/m/d',
        30 => 'm/d/yy',
        31 => 'yyyy/m/d',
        32 => 'h:mm',
        33 => 'h:mm:ss',
        34 => 'yyyy/m/d',
        35 => 'yyyy/m/d',
        36 => 'yyyy/m/d',
        37 => '#,##0',
        38 => '#,##0',
        39 => '#,##0.00',
        40 => '#,##0.00',
        41 => '#,##0',
        42 => '#,##0',
        43 => '#,##0.00',
        44 => '#,##0.00',
        45 => 'mm:ss',
        46 => '[h]:mm:ss',
        47 => 'mm:ss.0',
        48 => '##0.0E+0',
        49 => '@',
        50 => 'yyyy/m/d',
        51 => 'yyyy/m/d',
        52 => 'yyyy/m/d',
        53 => 'yyyy/m/d',
        54 => 'yyyy/m/d',
        55 => 'yyyy/m/d',
        56 => 'yyyy/m/d',
        57 => 'yyyy/m/d',
        58 => 'yyyy/m/d',
    ];

    /**
     * The code behind a built-in format number; null for a number a workbook has to define itself.
     */
    public static function builtInCode(int $formatId): null|string
    {
        return self::BUILT_IN[$formatId] ?? null;
    }

    public static function classify(string $code): NumberFormat
    {
        [$bare, $elapsed] = self::withoutLiterals($code);

        // AM/PM holds an "m" that is neither a month nor a minute
        $bare = str_replace(['am/pm', 'a/p'], '', $bare);

        if (trim($bare) === '' || str_contains($bare, 'general')) {
            return NumberFormat::general();
        }

        // 0.00E+00: the exponent is not the "e" some versions use for a year
        $bare = preg_replace('/e[+-][0#?]+/', '', $bare) ?? $bare;

        preg_match_all('/y+|e+|m+|d+|h+|s+/', $bare, $matches);
        $tokens = $matches[0];

        if ($tokens === []) {
            return match (true) {
                str_contains($bare, '@') => new NumberFormat(NumberFormatKind::Text),
                default => new NumberFormat(NumberFormatKind::Number, percent: str_contains($bare, '%')),
            };
        }

        $year = false;
        $month = false;
        $day = false;
        $time = false;

        foreach ($tokens as $index => $token) {
            switch ($token[0]) {
                case 'y':
                case 'e':
                    $year = true;

                    break;
                case 'd':
                    $day = true;

                    break;
                case 'h':
                case 's':
                    $time = true;

                    break;
                default:
                    $before = $tokens[$index - 1] ?? '';
                    $after = $tokens[$index + 1] ?? '';

                    // "mmm" and longer is a month's name wherever it stands
                    if (strlen($token) <= 2 && (str_starts_with($before, 'h') || str_starts_with($after, 's'))) {
                        $time = true;
                    } else {
                        $month = true;
                    }
            }
        }

        if ($elapsed) {
            return new NumberFormat(NumberFormatKind::Duration);
        }

        if ($year === false && $month === false && $day === false) {
            return new NumberFormat(NumberFormatKind::Time);
        }

        return new NumberFormat(
            NumberFormatKind::Date,
            precision: match (true) {
                $day => DatePrecision::Day,
                $month => DatePrecision::Month,
                default => DatePrecision::Year,
            },
            yearShown: $year,
            timeShown: $time,
        );
    }

    /**
     * The first section of a code, lower case, without anything that is shown as it stands: "quoted text", \x (the
     * next character), _x (a space as wide as x), *x (x repeated), [Red], [>100], [$-409], [DBNum1]. An elapsed-time
     * bracket stays as its unit letters.
     *
     * @return array{string, bool} the bare code, and whether it holds an elapsed-time bracket ([h], [mm], [s] …)
     */
    private static function withoutLiterals(string $code): array
    {
        $bare = '';
        $elapsed = false;
        $length = strlen($code);
        $position = 0;

        while ($position < $length) {
            $character = $code[$position];

            if ($character === '"') {
                $end = strpos($code, '"', $position + 1);
                $position = $end === false ? $length : $end + 1;

                continue;
            }

            if ($character === '\\' || $character === '_' || $character === '*') {
                $position += 2;

                continue;
            }

            if ($character === '[') {
                $end = strpos($code, ']', $position + 1);

                if ($end === false) {
                    break;
                }

                $inner = strtolower(substr($code, $position + 1, $end - $position - 1));

                if (preg_match('/^(h+|m+|s+)$/', $inner) === 1) {
                    $elapsed = true;
                    // [m] and [mm] are minutes whatever stands next to them
                    $bare .= $inner[0] === 'm' ? 'h' : $inner;
                }

                $position = $end + 1;

                continue;
            }

            if ($character === ';') {
                break;
            }

            $bare .= $character;
            $position++;
        }

        return [strtolower($bare), $elapsed];
    }
}
