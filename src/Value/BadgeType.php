<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum BadgeType: string
{
    case Supporter = 'supporter';
    case PuzzlesSolved = 'puzzles_solved';
    case PiecesSolved = 'pieces_solved';
    case Speed500Pieces = 'speed_500_pieces';
    case Streak = 'streak';
    case TeamPlayer = 'team_player';

    public function isTiered(): bool
    {
        return $this !== self::Supporter;
    }

    public function translationKey(): string
    {
        return 'badges.badge.' . $this->value;
    }
}
