<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum SuspiciousTimeTier: string
{
    // Far beyond the expectation, or explained by a likely mistake
    case Strong = 'strong';
    case Possible = 'possible';
}
