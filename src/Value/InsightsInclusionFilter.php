<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A yes/no property of a result or its puzzle (at an event, unboxed, on your shelf): keep every result, only the ones
 * that have it, or leave them out (members).
 */
enum InsightsInclusionFilter: string
{
    case Any = 'any';
    case Only = 'only';
    case Exclude = 'exclude';

    public function accepts(bool $has): bool
    {
        return match ($this) {
            self::Any => true,
            self::Only => $has,
            self::Exclude => $has === false,
        };
    }
}
