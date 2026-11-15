<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * "Go on with this import": the player's own finished import is set up again where it stood - while its rows still
 * hold what the spreadsheet said and one of the player's places is free. Answers an ImportClosingOutcome.
 */
readonly final class GoOnWithImport implements SerializedByLock
{
    public function __construct(
        public string $importId,
        public string $playerId,
    ) {
    }

    public function lockKey(): string
    {
        return 'data-import-add-' . strtolower($this->playerId);
    }
}
