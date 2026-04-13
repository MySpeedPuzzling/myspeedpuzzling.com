<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum EmailAuditStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
}
