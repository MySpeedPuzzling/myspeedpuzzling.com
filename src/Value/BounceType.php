<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum BounceType: string
{
    case Hard = 'hard';
    case Soft = 'soft';
}
