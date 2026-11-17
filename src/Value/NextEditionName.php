<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The name "Duplicate" proposes for the next edition of a series (docs/features/competitions-management/README.md
 * "Duplicating an event"): a name ending in a number gets the next one ("Cup #4" → "Cup #5", "Week 09" → "Week 10"),
 * any other name stays as it is - the organiser sees it and can change it.
 */
final class NextEditionName
{
    public static function of(string $name): string
    {
        if (preg_match('/^(.*?)(\d+)$/su', $name, $matches) !== 1 || strlen($matches[2]) > 9) {
            return $name;
        }

        $next = (string) ((int) $matches[2] + 1);

        return $matches[1] . str_pad($next, strlen($matches[2]), '0', STR_PAD_LEFT);
    }
}
