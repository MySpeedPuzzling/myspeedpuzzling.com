<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\DeleteRoundResult;
use SpeedPuzzling\Web\Repository\RoundResultRepository;
use SpeedPuzzling\Web\Services\RoundResultsPublisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteRoundResultHandler
{
    public function __construct(
        private RoundResultRepository $resultRepository,
        private RoundResultsPublisher $publisher,
    ) {
    }

    public function __invoke(DeleteRoundResult $message): void
    {
        // Idempotent: deleting an already-deleted result is a no-op (offline replays)
        $result = $this->resultRepository->find($message->resultId);

        if ($result === null) {
            return;
        }

        $this->publisher->publishResultDeleted($result);
        $this->resultRepository->delete($result);
    }
}
