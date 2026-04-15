<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum EditTimeReturnContext: string
{
    case Profile = 'profile';
    case PuzzleDetail = 'puzzle-detail';
}
