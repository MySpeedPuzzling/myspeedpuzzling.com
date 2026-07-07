<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class PublishRoundResults
{
    public function __construct(
        public string $roundId,
        public bool $notifyParticipants = true,
    ) {
    }
}
