<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * A chat message from the MySpeedPuzzling team (the internal API's reviewer player) to a player - reaches them in the
 * app even when e-mail does not (docs/features/internal-api.md "Messages to players"). The chat is open from the start:
 * no conversation request step, the player's "direct messages off" does not apply, and they can answer.
 */
readonly final class SendTeamChatMessage
{
    public const int CONTENT_MAX_LENGTH = 2000;

    public function __construct(
        public string $senderId,
        public string $recipientId,
        public string $content,
    ) {
    }
}
