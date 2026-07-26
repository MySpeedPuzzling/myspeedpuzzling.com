<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum NewsletterSubscriberStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Unsubscribed = 'unsubscribed';
}
