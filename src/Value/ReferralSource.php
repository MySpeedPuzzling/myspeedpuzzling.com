<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum ReferralSource: string
{
    case Link = 'link';
    case Code = 'code';
    case Manual = 'manual';
}
