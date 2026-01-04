<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Events\PuzzleMergeApproved;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteMergedPuzzlesOnMergeApproved
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PuzzleMergeApproved $event): void
    {
        $deletedCount = 0;

        foreach ($event->puzzleIdsToDelete as $puzzleId) {
            try {
                $puzzle = $this->puzzleRepository->get($puzzleId);
                $this->entityManager->remove($puzzle);
                $deletedCount++;
            } catch (PuzzleNotFound) {
                $this->logger->debug('Puzzle {puzzleId} already deleted during merge', [
                    'puzzleId' => $puzzleId,
                    'mergeRequestId' => $event->mergeRequestId->toString(),
                ]);
            }
        }

        $this->logger->info('Deleted {deletedCount} merged puzzles', [
            'mergeRequestId' => $event->mergeRequestId->toString(),
            'survivorPuzzleId' => $event->survivorPuzzleId->toString(),
            'deletedCount' => $deletedCount,
        ]);
    }
}
