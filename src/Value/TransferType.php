<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum TransferType: string
{
    case InitialLend = 'initial_lend';
    case Pass = 'pass';
    case Return = 'return';
}
