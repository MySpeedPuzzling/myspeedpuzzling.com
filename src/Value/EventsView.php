<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum EventsView: string
{
    case List = 'list';
    case Calendar = 'calendar';

    public static function fromQuery(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::List) : self::List;
    }
}
