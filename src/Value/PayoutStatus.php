<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum PayoutStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
}
