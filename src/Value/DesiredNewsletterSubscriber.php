<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Results\NewsletterRecipient;

/**
 * A newsletter recipient enriched with the Listmonk subscriber attributes it
 * should carry - the "desired state" input of the sync planner.
 */
readonly final class DesiredNewsletterSubscriber
{
    public function __construct(
        public NewsletterRecipient $recipient,
        /** @var array<string, string> */
        public array $attributes,
    ) {
    }
}
