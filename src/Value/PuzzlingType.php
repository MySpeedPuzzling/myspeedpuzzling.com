<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum PuzzlingType: string
{
    case Solo = 'solo';
    case Duo = 'duo';
    case Team = 'team';

    public static function fromPuzzlersCount(int $count): self
    {
        return match (true) {
            $count === 1 => self::Solo,
            $count === 2 => self::Duo,
            default => self::Team,
        };
    }
}
