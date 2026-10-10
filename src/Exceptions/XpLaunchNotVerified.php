<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use RuntimeException;
use SpeedPuzzling\Web\Results\XpLaunchVerification;

final class XpLaunchNotVerified extends RuntimeException
{
    public function __construct(
        readonly public XpLaunchVerification $verification,
    ) {
        parent::__construct('XP launch verification failed: ' . implode('; ', $verification->problems()));
    }
}
