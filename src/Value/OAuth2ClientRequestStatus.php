<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum OAuth2ClientRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
