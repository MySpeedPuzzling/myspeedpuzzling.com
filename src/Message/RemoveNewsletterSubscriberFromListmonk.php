<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Hard-delete an e-mail address from Listmonk (GDPR player deletion). Carries
 * the address itself because by the time the message is handled the player row
 * is gone.
 */
readonly final class RemoveNewsletterSubscriberFromListmonk
{
    public function __construct(
        public string $email,
    ) {
    }
}
