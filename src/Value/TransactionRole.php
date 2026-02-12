<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum TransactionRole: string
{
    case Seller = 'seller';
    case Buyer = 'buyer';
}
