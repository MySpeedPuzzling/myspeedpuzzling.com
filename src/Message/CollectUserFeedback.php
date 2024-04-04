<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class CollectUserFeedback
{
    public function __construct(
        public string $url,
        public string $message,
    ) {
    }
}
