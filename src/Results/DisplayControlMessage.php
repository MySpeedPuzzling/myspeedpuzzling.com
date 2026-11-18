<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The live control's message after a write (SetCompetitionDisplayMessage): what the displays show now and the recent
 * messages for one-tap reuse, newest first.
 */
readonly final class DisplayControlMessage
{
    /**
     * @param list<string> $recentMessages
     */
    public function __construct(
        public null|string $message,
        public array $recentMessages,
    ) {
    }
}
