<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\SetUpPastEventRounds;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every round and its puzzle run nested in this handler's transaction: a refused puzzle (a secret puzzle of somebody
 * else, a placeholder hidden by hand) rolls every row back.
 */
#[AsMessageHandler]
readonly final class SetUpPastEventRoundsHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(SetUpPastEventRounds $message): void
    {
        foreach ($message->rounds as $row) {
            $this->messageBus->dispatch($row['round']);
            $this->messageBus->dispatch($row['puzzle']);
        }
    }
}
