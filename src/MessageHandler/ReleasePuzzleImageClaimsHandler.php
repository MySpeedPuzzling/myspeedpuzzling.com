<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\ReleasePuzzleImageClaims;
use SpeedPuzzling\Web\Repository\PuzzleImageAnalysisClaimRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ReleasePuzzleImageClaimsHandler
{
    public function __construct(
        private PuzzleImageAnalysisClaimRepository $puzzleImageAnalysisClaimRepository,
    ) {
    }

    /**
     * @return int how many claims were released
     */
    public function __invoke(ReleasePuzzleImageClaims $message): int
    {
        $claims = $this->puzzleImageAnalysisClaimRepository->findOfWorker($message->worker, $message->analyzer, $message->puzzleIds);

        foreach ($claims as $claim) {
            $this->puzzleImageAnalysisClaimRepository->delete($claim);
        }

        return count($claims);
    }
}
