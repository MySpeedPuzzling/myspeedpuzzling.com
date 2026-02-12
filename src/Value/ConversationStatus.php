<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum ConversationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Denied = 'denied';
}
