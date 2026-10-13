<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use RuntimeException;

/**
 * The phone reported an evidence object uploaded, but its store does not have it (yet) - VerifyOnlineMedia fails so the
 * async transport tries again later (its retry strategy), and the media stays unverified meanwhile.
 */
final class OnlineMediaObjectMissing extends RuntimeException
{
    public function __construct(string $mediaId)
    {
        parent::__construct(sprintf('Online media "%s" is not in its store (yet).', $mediaId));
    }
}
