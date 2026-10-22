<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The "Partner finder e-mails" switch (`player.partner_finder_emails`, docs/features/partner-finder.md D6): the
 * settings page and the e-mail's one-click unsubscribe.
 */
readonly final class ChangePartnerFinderEmails
{
    public function __construct(
        public string $playerId,
        public bool $enabled,
    ) {
    }
}
