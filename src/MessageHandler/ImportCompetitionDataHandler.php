<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\ImportCompetitionData;
use SpeedPuzzling\Web\Value\SolvingTime;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ImportCompetitionDataHandler
{
    public function __construct()
    {
    }

    public function __invoke(ImportCompetitionData $message): void
    {
        $resultTime = SolvingTime::fromUserInput($message->resultTime);

        // TODO: Find competition
        // TODO: Create if not exists
        // TODO: Find Round
        // TODO: Create if not exists
        // TODO: find puzzle_id if exists by name
        // TODO: find result
        // TODO: create if not exist
    }
}
