<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum EmailAuditStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
}
