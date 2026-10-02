<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum ResultReviewContactStatus: string
{
    // Waiting for the paced sending job
    case Planned = 'planned';
    case Sent = 'sent';
    // Not sent after all - see ResultReviewContactSkipReason
    case Skipped = 'skipped';
}
