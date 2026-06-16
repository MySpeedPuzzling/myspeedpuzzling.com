<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum ComparisonMode: string
{
    case Solo = 'solo';
    case Pairs = 'pairs';
    case Teams = 'teams';

    public static function fromString(null|string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Solo;
    }

    public function puzzlingType(): PuzzlingType
    {
        return match ($this) {
            self::Solo => PuzzlingType::Solo,
            self::Pairs => PuzzlingType::Duo,
            self::Teams => PuzzlingType::Team,
        };
    }

    /**
     * Maximum number of co-solvers that can narrow a subject in this mode.
     * Pairs = 1 extra puzzler (2 total), Teams = unbounded, Solo = none.
     */
    public function maxCoSolvers(): null|int
    {
        return match ($this) {
            self::Solo => 0,
            self::Pairs => 1,
            self::Teams => null,
        };
    }
}
