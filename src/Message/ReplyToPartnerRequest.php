<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The listed puzzler answers an "I'd like to puzzle with you" (docs/features/partner-finder.md D6): a chat with whoever
 * asked opens from the listed puzzler's side, with a first line written for them in their language.
 */
readonly final class ReplyToPartnerRequest
{
    public function __construct(
        public string $requestId,
        // Who replies - must own the listing the request was sent to
        public string $playerId,
        // The language of the pre-filled first message - the replier's page; null = their profile's
        public null|string $locale = null,
    ) {
    }
}
