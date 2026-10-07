<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\DeleteOfficialRoundResult;
use SpeedPuzzling\Web\Repository\OfficialRoundResultRepository;
use SpeedPuzzling\Web\Services\RoundResultsPublisher;
use SpeedPuzzling\Web\Services\SolvingTimeRemover;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteOfficialRoundResultHandler
{
    public function __construct(
        private OfficialRoundResultRepository $resultRepository,
        private RoundResultsPublisher $publisher,
        private SolvingTimeRemover $solvingTimeRemover,
    ) {
    }

    public function __invoke(DeleteOfficialRoundResult $message): void
    {
        // Idempotent: deleting an already-deleted result is a no-op (offline replays)
        $result = $this->resultRepository->find($message->resultId);

        if ($result === null) {
            return;
        }

        // Claim-created profile times fall with the official result; linked
        // self-logged times are never deleted
        if ($result->solvingTime !== null && $result->claimCreatedSolvingTime === true) {
            $this->solvingTimeRemover->remove($result->solvingTime);
        }

        $this->publisher->publishResultDeleted($result);
        $this->resultRepository->delete($result);
    }
}
