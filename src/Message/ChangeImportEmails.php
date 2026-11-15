<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The "Import e-mails" switch (`player.import_emails`, docs/features/data-import/adding.md "Telling the player"): the
 * e-mail's one-click unsubscribe (the settings page saves it with the other switches - EditMessagingSettings).
 */
readonly final class ChangeImportEmails
{
    public function __construct(
        public string $playerId,
        public bool $enabled,
    ) {
    }
}
