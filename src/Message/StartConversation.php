<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class StartConversation
{
    public function __construct(
        public string $initiatorId,
        public string $recipientId,
        public string $initialMessage,
        public null|string $sellSwapListItemId = null,
        public null|string $puzzleId = null,
        // The listed puzzler answers an "I'd like to puzzle with you" (docs/features/partner-finder.md D6): the
        // recipient asked first, so their "direct messages off" does not apply - the handler checks the request
        public null|string $partnerRequestId = null,
    ) {
    }
}
