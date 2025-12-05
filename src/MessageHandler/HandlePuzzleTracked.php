<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\PuzzleTracked;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class HandlePuzzleTracked
{
    public function __invoke(PuzzleTracked $event): void
    {
        // Event handled - no additional actions needed for now
        // This can be extended later for notifications, statistics updates, etc.
    }
}
