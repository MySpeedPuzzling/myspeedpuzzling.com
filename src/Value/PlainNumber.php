<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use NumberFormatter;

/**
 * How MySpeedPuzzling writes a number (Jan, 2026-10-11): never a comma between the thousands. Up to 99999 the digits
 * stand alone ("4141", "10000"); from 100000 the thousands are set apart by a no-break space ("1 234 567"), the way the
 * site always wrote big totals. The decimal separator follows the locale. The one rule behind the Twig filter
 * `plain_number` and every number PHP puts into a text.
 */
final class PlainNumber
{
    /** From this size up the thousands get a no-break space */
    public const int GROUPING_FROM = 100_000;

    private const string NO_BREAK_SPACE = "\u{00A0}";

    public static function format(int|float $number, null|string $locale = null, null|int $decimals = null): string
    {
        $formatter = new NumberFormatter($locale ?? \Locale::getDefault(), NumberFormatter::DECIMAL);

        if ($decimals !== null) {
            $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
        }

        if (abs($number) < self::GROUPING_FROM) {
            $formatter->setAttribute(NumberFormatter::GROUPING_USED, 0);
        } else {
            $formatter->setSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL, self::NO_BREAK_SPACE);
        }

        $formatted = $formatter->format($number);

        return is_string($formatted) ? $formatted : (string) $number;
    }
}
