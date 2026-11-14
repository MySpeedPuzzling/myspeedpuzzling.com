<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use Normalizer;

/**
 * How the value readers look at a typed text (docs/features/data-import/value-readers.md "Text first"): what is not
 * meant is taken away before anything is read - broken bytes, the full-width forms of digits and marks
 * (`１２：３４` is `12:34`), non-breaking and other odd spaces, control and direction marks, the emoji variation mark.
 */
final readonly class ImportText
{
    /** A value longer than this (in characters) is no time, date, piece count or flag - it is a note */
    public const int MAX_VALUE_LENGTH = 200;

    /**
     * NFKC, every whitespace run one space, control / format / unassigned characters gone, trimmed.
     */
    public static function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');

        // Plain printable ASCII without a space needs none of it - most cells
        if (preg_match('/[^\x21-\x7E]/', $text) !== 1) {
            return $text;
        }

        $normalised = Normalizer::normalize($text, Normalizer::FORM_KC);
        $text = is_string($normalised) ? $normalised : $text;

        $text = preg_replace('/[\s\p{Z}]+/u', ' ', $text) ?? '';
        $text = preg_replace('/[\p{Cc}\p{Cf}\p{Co}\p{Cn}\x{FE00}-\x{FE0F}]+/u', '', $text) ?? '';
        $text = preg_replace('/ {2,}/', ' ', $text) ?? '';

        return trim($text, ' ');
    }

    /**
     * A number as a text - also the ones that are none (casting NAN to a string is a warning).
     */
    public static function ofNumber(int|float $number): string
    {
        if (is_float($number) && is_nan($number)) {
            return 'NaN';
        }

        if (is_float($number) && is_infinite($number)) {
            return $number > 0 ? 'INF' : '-INF';
        }

        return (string) $number;
    }

    /**
     * Whether a raw text is too long to be a single value - checked before any pattern is run over it.
     */
    public static function isTooLong(string $text): bool
    {
        return strlen($text) > self::MAX_VALUE_LENGTH * 4 || mb_strlen(mb_scrub($text, 'UTF-8'), 'UTF-8') > self::MAX_VALUE_LENGTH;
    }

    /**
     * A cleaned text in lower case, Latin letters without their accents ("3. března" → "3. brezna", "März" → "marz") -
     * for comparing with word lists. Other scripts stay as they are (a Japanese voiced mark is part of its letter).
     */
    public static function plain(string $cleaned): string
    {
        $lower = mb_strtolower($cleaned, 'UTF-8');

        if (preg_match('/[^\x00-\x7F]/', $lower) !== 1) {
            return $lower;
        }

        $decomposed = Normalizer::normalize($lower, Normalizer::FORM_D);

        if (is_string($decomposed) === false) {
            return $lower;
        }

        $stripped = preg_replace('/(?<=\p{Latin})\p{Mn}+/u', '', $decomposed) ?? $decomposed;
        $composed = Normalizer::normalize($stripped, Normalizer::FORM_C);

        return is_string($composed) ? $composed : $stripped;
    }
}
