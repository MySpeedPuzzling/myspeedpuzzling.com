<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum PuzzleAddMode: string
{
    case SpeedPuzzling = 'speed_puzzling';
    case Relax = 'relax';
    case Collection = 'collection';
}
