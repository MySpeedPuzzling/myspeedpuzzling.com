<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The e-mail about an "I'd like to puzzle with you" (docs/features/partner-finder.md D6) - async, dispatched after the
 * request's transaction committed. The handler reads everything fresh and skips what changed meanwhile.
 */
readonly final class SendPartnerRequestEmail
{
    public function __construct(
        public string $requestId,
    ) {
    }
}
