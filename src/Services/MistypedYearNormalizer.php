<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;

/**
 * Repairs solving-time dates whose year lost its century. Users enter the finish
 * date as a plain `d.m.Y` string, so typing "16.06.26" is parsed as the year 0026
 * instead of 2026. Such a value (~2000 years in the past) breaks statistics pages.
 *
 * Two-digit years are mapped with a fixed pivot:
 *   - 0–39  => 20xx (2000–2039)
 *   - 40–99 => 19xx (1940–1999)
 *
 * Full years (>= 100) are left untouched.
 */
readonly final class MistypedYearNormalizer
{
    /**
     * Two-digit years below this pivot are treated as 20xx, the rest as 19xx.
     */
    private const int CENTURY_PIVOT = 40;

    public function normalizeFinishedAt(null|DateTimeImmutable $finishedAt): null|DateTimeImmutable
    {
        if ($finishedAt === null) {
            return null;
        }

        $year = (int) $finishedAt->format('Y');

        if ($year >= 100) {
            return $finishedAt;
        }

        $normalizedYear = $year < self::CENTURY_PIVOT
            ? 2000 + $year
            : 1900 + $year;

        return $finishedAt->setDate(
            $normalizedYear,
            (int) $finishedAt->format('n'),
            (int) $finishedAt->format('j'),
        );
    }
}
