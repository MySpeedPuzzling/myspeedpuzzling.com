<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which end of a public difficulty ranking a page shows. The backed value is
 * the `direction` route default of the hardest / easiest list routes.
 */
enum DifficultyRankingDirection: string
{
    case Hardest = 'hardest';
    case Easiest = 'easiest';

    public function opposite(): self
    {
        return match ($this) {
            self::Hardest => self::Easiest,
            self::Easiest => self::Hardest,
        };
    }

    /**
     * SQL sort direction of puzzle_difficulty.difficulty_score.
     */
    public function scoreOrder(): string
    {
        return match ($this) {
            self::Hardest => 'DESC',
            self::Easiest => 'ASC',
        };
    }
}
