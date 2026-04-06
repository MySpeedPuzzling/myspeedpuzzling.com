<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum PuzzleHideMode: string
{
    case ImageOnly = 'image_only';
    case Entirely = 'entirely';
}
