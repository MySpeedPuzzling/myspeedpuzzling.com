<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum RoundCategory: string
{
    case Solo = 'solo';
    case Duo = 'duo';
    case Team = 'team';
}
