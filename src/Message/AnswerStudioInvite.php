<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\StudioInviteAnswer;

/**
 * A participant answers an invitation to the studio from their personal link (contract phase-1b.md §4,
 * `compete_api_studio_answer`): "Join" → `joined` (their phone then asks for a studio token), "Not now" → `declined`.
 * The same answer twice is answered again, never refused (a double tap); the handler answers the invitation
 * (HandledStamp).
 *
 * The invitation's row is locked for the write (StudioInviteRepository::getForUpdate()) - an answer and the host's end
 * never overwrite each other.
 */
readonly final class AnswerStudioInvite
{
    public function __construct(
        public string $inviteId,
        // The personal link's participant - an invitation of anybody else is not found
        public string $participantId,
        public StudioInviteAnswer $answer,
    ) {
    }
}
