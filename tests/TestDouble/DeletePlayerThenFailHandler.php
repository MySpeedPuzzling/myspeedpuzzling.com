<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use SpeedPuzzling\Web\Message\DeletePlayer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class DeletePlayerThenFailHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(DeletePlayerThenFail $message): void
    {
        $this->messageBus->dispatch(new DeletePlayer($message->playerId));

        throw new \RuntimeException('Simulated failure after the player was deleted');
    }
}
