<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum ListingType: string
{
    case Swap = 'swap';
    case Sell = 'sell';
    case Both = 'both';
}
