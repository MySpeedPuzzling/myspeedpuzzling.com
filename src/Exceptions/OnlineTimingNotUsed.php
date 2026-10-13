<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Personal links refused for an event no round of which uses online timing (docs/features/online-competitions/): a link
 * would open nothing (GenerateOnlineTokens, RegenerateOnlineToken). Nothing was changed.
 */
final class OnlineTimingNotUsed extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('No round of the event uses online timing.');
    }
}
