<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum TributeSource: string
{
    case Link = 'link';
    case Code = 'code';
    case Manual = 'manual';
}
