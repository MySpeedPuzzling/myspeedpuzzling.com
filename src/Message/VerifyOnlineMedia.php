<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Checks an uploaded evidence object is really there and how big it is (async: a HEAD request to object storage) -
 * OnlineEntryMedia::$verifiedBytes.
 */
readonly final class VerifyOnlineMedia
{
    public function __construct(
        public string $mediaId,
    ) {
    }
}
