<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleRedirect;
use SpeedPuzzling\Web\Events\PuzzleMergeApproved;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Repository\PuzzleRedirectRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteMergedPuzzlesOnMergeApproved
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PuzzleRedirectRepository $puzzleRedirectRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PuzzleMergeApproved $event): void
    {
        $deletedCount = 0;
        $puzzleIdsToDelete = array_values(array_unique($event->puzzleIdsToDelete));

        // Existing redirects pointing to a puzzle deleted now must follow the chain to the new survivor
        $this->puzzleRedirectRepository->redirectToNewSurvivor($puzzleIdsToDelete, $event->survivorPuzzleId);

        foreach ($puzzleIdsToDelete as $puzzleId) {
            if ($this->puzzleRedirectRepository->findByOldPuzzleId($puzzleId) === null) {
                $this->puzzleRedirectRepository->save(
                    new PuzzleRedirect(
                        id: Uuid::uuid7(),
                        oldPuzzleId: Uuid::fromString($puzzleId),
                        survivorPuzzleId: $event->survivorPuzzleId,
                        createdAt: $this->clock->now(),
                    ),
                );
            }

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
