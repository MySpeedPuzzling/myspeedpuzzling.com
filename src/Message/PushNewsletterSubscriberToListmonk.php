<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Mirror the current MySpeedPuzzling subscription state of one e-mail address
 * into Listmonk right away (profile toggle, opt-in confirm, unsubscribe page),
 * without waiting for the next reconciliation run. Unlike the cron sync this
 * IS allowed to flip a Listmonk unsubscribe back to confirmed - it only fires
 * on explicit user actions.
 */
readonly final class PushNewsletterSubscriberToListmonk
{
    public function __construct(
        public string $email,
    ) {
    }
}
