<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum PuzzleCondition: string
{
    case New = 'new';
    case LikeNew = 'like_new';
    case Normal = 'normal';
    case NotSoGood = 'not_so_good';
    case MissingPieces = 'missing_pieces';

    public function toSchemaOrgCondition(): string
    {
        return match ($this) {
            self::New => 'https://schema.org/NewCondition',
            self::MissingPieces => 'https://schema.org/DamagedCondition',
            default => 'https://schema.org/UsedCondition',
        };
    }
}
