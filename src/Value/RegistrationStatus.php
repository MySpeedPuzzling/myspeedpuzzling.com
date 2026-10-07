<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum RegistrationStatus: string
{
    case Reserved = 'reserved';
    case Paid = 'paid';
    case Waitlisted = 'waitlisted';
}
