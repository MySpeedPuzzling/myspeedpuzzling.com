<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPuzzleSolvingTimeHandler
{
    public function __invoke(AddPuzzleSolvingTime $message): void
    {

    }
}
