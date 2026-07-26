<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\NewsletterAudience;

/**
 * One e-mail address in the newsletter audience with its desired subscription
 * state - the MySpeedPuzzling side of the Listmonk sync. Exactly one recipient
 * exists per e-mail: when an address belongs to both a player and a guest
 * subscriber, the player wins.
 */
readonly final class NewsletterRecipient
{
    public function __construct(
        public NewsletterAudience $audience,
        public string $id,
        /** Lowercased, trimmed */
        public string $email,
        public string $name,
        public null|string $locale,
        public bool $subscribed,
    ) {
    }
}
