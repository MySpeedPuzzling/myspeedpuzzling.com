<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum AffiliateStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
}
