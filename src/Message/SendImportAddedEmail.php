<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The e-mail "your import is done" for one finished batch (docs/features/data-import/adding.md "Telling the
 * player") - async, never in the worker's turn.
 */
readonly final class SendImportAddedEmail
{
    public function __construct(
        public string $batchId,
    ) {
    }
}
