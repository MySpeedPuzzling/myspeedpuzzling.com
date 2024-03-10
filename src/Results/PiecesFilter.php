<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

enum PiecesFilter: string
{
    case Any = 'any';
    case UpTo499 = '1-499';
    case Exactly500 = '500';
    case UpTo999 = '501-999';
    case Exactly1000 = '1000';
    case MoreThan1000 = '1001+';

    public static function fromUserInput(null|string $input): self
    {
        if ($input === null) {
            return self::Any;
        }

        return self::tryFrom($input) ?? self::Any;
    }

    public function minPieces(): int
    {
        return match ($this) {
            self::Any => 0,
            self::UpTo499 => 1,
            self::Exactly500 => 500,
            self::UpTo999 => 501,
            self::Exactly1000 => 1000,
            self::MoreThan1000 => 1001,
        };
    }

    public function maxPieces(): int
    {
        return match ($this) {
            self::Any,
            self::MoreThan1000 => 99999,
            self::UpTo499 => 499,
            self::Exactly500 => 500,
            self::UpTo999 => 999,
            self::Exactly1000 => 1000,
        };
    }
}
