<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class CollectUserFeedback
{
    /**
     * @param list<string> $screenshotTokens PhotoStash tokens of the player's screenshots - attached to the e-mail
     * @param int $lostScreenshots screenshots the player added that could not even be kept - the e-mail says so
     */
    public function __construct(
        public string $url,
        public string $message,
        public array $screenshotTokens = [],
        public int $lostScreenshots = 0,
    ) {
    }
}
