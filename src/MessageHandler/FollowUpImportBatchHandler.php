<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Message\FollowUpImportBatch;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportedResultsFollowUp;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultOutcome;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One chunk of a batch's follow-up (docs/features/data-import/adding.md "The end of a batch"): the next
 * RESULTS_PER_CHUNK results the batch made, behind the place the batch remembers (`followed_up_to`), under the
 * batch's lock - a chunk is followed up once. A result deleted meanwhile owes nothing. Whether a solo result waits
 * for its prediction is remembered on the batch for its end.
 */
#[AsMessageHandler]
readonly final class FollowUpImportBatchHandler
{
    public const int RESULTS_PER_CHUNK = 100;
    public const string XP_TRANSPORT = 'imports';

    public function __construct(
        private DataImportBatchRepository $batchRepository,
        private DataImportRowRepository $rowRepository,
        private ImportedResultsFollowUp $followUp,
        private EntityManagerInterface $entityManager,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool true: every result of the batch is followed up
     */
    public function __invoke(FollowUpImportBatch $message): bool
    {
        if (Uuid::isValid($message->batchId) === false) {
            return true;
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->batchId));
        $batch = $this->batchRepository->find($message->batchId);

        if ($batch === null || $batch->isRunning() === false || $batch->followedUpAt !== null) {
            return true;
        }

        $made = $this->rowRepository->solvingTimeIdsMadeByBatch($message->batchId, $batch->followedUpTo?->toString(), self::RESULTS_PER_CHUNK);
        $now = $this->clock->now();

        if ($made === []) {
            $batch->followedUpSoFar(null, true, false, $now);

            return true;
        }

        $outcomes = [];

        foreach ($made as $rowId => $solvingTimeId) {
            $solvingTime = $this->entityManager->find(PuzzleSolvingTime::class, $solvingTimeId);

            if ($solvingTime === null) {
                continue;
            }

            $outcomes[] = ImportedResultOutcome::added(
                importRowId: $rowId,
                solvingTimeId: $solvingTimeId,
                puzzleId: $solvingTime->puzzle->id->toString(),
                memberPlayerIds: $solvingTime->memberPlayerIds(),
                predictionPending: $solvingTime->isPredictionPending(),
                removedWishListItem: null,
            );
        }

        $owed = $this->followUp->forResults($outcomes, self::XP_TRANSPORT);
        $batch->followedUpSoFar(Uuid::fromString((string) array_key_last($made)), false, $owed['playersAwaitingPredictions'] !== [], $now);

        return false;
    }
}
