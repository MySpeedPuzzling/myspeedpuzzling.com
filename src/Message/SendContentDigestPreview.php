<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\ContentDigestKind;

/**
 * One player's real digest e-mail sent to one address (myspeedpuzzling:send-content-digest-preview) - not routed,
 * handled at once, so the command reports what the transport answered
 */
readonly final class SendContentDigestPreview
{
    public function __construct(
        public string $playerId,
        public string $emailAddress,
        public ContentDigestKind $kind,
        public string $periodKey,
    ) {
    }
}
