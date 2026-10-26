<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The "Borrow request e-mails" switch (`player.borrow_request_emails`, docs/features/wishlist-availability.md): the
 * settings page and the e-mail's one-click unsubscribe.
 */
readonly final class ChangeBorrowRequestEmails
{
    public function __construct(
        public string $playerId,
        public bool $enabled,
    ) {
    }
}
