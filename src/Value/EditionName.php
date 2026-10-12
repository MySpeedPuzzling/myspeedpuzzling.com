<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * An edition's own name: what is left of it once the series' name (or shortcut) it starts with is cut off -
 * "Euro Jigsaw Jam #168" in the series "Euro Jigsaw Jam" → "#168". Wherever the series stands in front of an
 * edition ("Euro Jigsaw Jam · #168", "EJJ · #168"), it would otherwise be said twice.
 *
 * Only a whole leading series name counts ("Euro Jigsaw Jamboree" stays as it is), compared case-insensitively,
 * and the separator after it goes too ("Euro Jigsaw Jam - 8 October" → "8 October"). Null when nothing but the
 * series name is left - an edition named like its series.
 */
final class EditionName
{
    private const string LEADING_SEPARATORS = '/^[\s\-–—·•:|,\/]+/u';

    public static function withoutSeries(string $editionName, null|string ...$seriesNames): null|string
    {
        $name = trim($editionName);

        foreach ($seriesNames as $seriesName) {
            $seriesName = trim((string) $seriesName);
            $length = mb_strlen($seriesName);

            if ($length === 0 || mb_strtolower(mb_substr($name, 0, $length)) !== mb_strtolower($seriesName)) {
                continue;
            }

            $rest = mb_substr($name, $length);

            // The series name is only the start of a longer word ("Euro Jigsaw Jamboree", "EJJ2026")
            if (preg_match('/^[\p{L}\p{N}]/u', $rest) === 1) {
                continue;
            }

            $rest = trim((string) preg_replace(self::LEADING_SEPARATORS, '', $rest));

            return $rest === '' ? null : $rest;
        }

        return $name === '' ? null : $name;
    }
}
