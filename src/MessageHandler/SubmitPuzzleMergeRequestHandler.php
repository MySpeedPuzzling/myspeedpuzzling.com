<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleMergeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SubmitPuzzleMergeRequestHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SubmitPuzzleMergeRequest $message): void
    {
        $sourcePuzzle = $this->puzzleRepository->get($message->sourcePuzzleId);
        $reporter = $this->playerRepository->get($message->reporterId);
        $now = $this->clock->now();

        // Validate all duplicate puzzle IDs exist
        foreach ($message->duplicatePuzzleIds as $puzzleId) {
            $this->puzzleRepository->get($puzzleId);
        }

        // Ensure source puzzle is included in the list
        $allPuzzleIds = array_unique(array_merge(
            [$message->sourcePuzzleId],
            $message->duplicatePuzzleIds,
        ));

        $mergeRequest = new PuzzleMergeRequest(
            id: Uuid::fromString($message->mergeRequestId),
            sourcePuzzle: $sourcePuzzle,
            reporter: $reporter,
            submittedAt: $now,
            reportedDuplicatePuzzleIds: $allPuzzleIds,
        );

        $this->entityManager->persist($mergeRequest);
    }
}
