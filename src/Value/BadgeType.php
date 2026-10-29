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
    case ZenPuzzler = 'zen_puzzler';
    case FirstTry = 'first_try';
    case Unboxed = 'unboxed';
    case BrandExplorer = 'brand_explorer';
    case Marathoner = 'marathoner';
    case Photographer = 'photographer';
    case SteadyHands = 'steady_hands';
    case Librarian = 'librarian';
    case SpeedDemon1000 = 'speed_1000_pieces';
    case WeekendPuzzler = 'weekend_puzzler';
    case Cataloger = 'cataloger';
    case MuscleMemory = 'muscle_memory';
    case MuscleMemoryUnboxed = 'muscle_memory_unboxed';
    case NightOwl = 'night_owl';
    case Competitor = 'competitor';
    case OnlineCompetitor = 'online_competitor';

    public function isTiered(): bool
    {
        return $this !== self::Supporter;
    }

    public function translationKey(): string
    {
        return 'badges.badge.' . $this->value;
    }
}
