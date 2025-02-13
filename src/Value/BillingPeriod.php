<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum BillingPeriod: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}
