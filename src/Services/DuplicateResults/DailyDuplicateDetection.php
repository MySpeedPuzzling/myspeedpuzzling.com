<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DuplicateResults;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\AutoRemoveCertainDuplicate;
use SpeedPuzzling\Web\Message\DetectDuplicateResults;
use SpeedPuzzling\Web\Message\ReclassifyDuplicateCasesAfterRemoval;
use SpeedPuzzling\Web\Results\DuplicateDetectionSummary;
use SpeedPuzzling\Web\Value\DuplicateDetectedBy;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * The daily run of `myspeedpuzzling:detect-duplicate-results` (docs/features/duplicate-results.md, "Detection"):
 * the detection stores and closes cases in its own transaction, then every open Tier A case is removed by a
 * top-level message of its own. A removal that fails is logged and its case stays open for the next run - it never
 * takes the detection or the other removals down with it.
 *
 * After each removal the cases around the kept copy are classified again; a pair that became certain (the outer two
 * of three re-sends) joins the queue of the same run.
 */
readonly final class DailyDuplicateDetection
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $managerRegistry,
        private LoggerInterface $logger,
    ) {
    }

    public function run(DuplicateDetectedBy $detectedBy): DuplicateDetectionSummary
    {
        $summary = $this->resultOf($this->messageBus->dispatch(new DetectDuplicateResults($detectedBy)));
        assert($summary instanceof DuplicateDetectionSummary);
        $this->freshEntityManager();

        $queue = $summary->certainCaseIds;
        $handled = [];
        $removed = 0;
        $failed = 0;

        while (($caseId = array_shift($queue)) !== null) {
            if (isset($handled[$caseId])) {
                continue;
            }

            $handled[$caseId] = true;

            try {
                $wasRemoved = $this->resultOf($this->messageBus->dispatch(new AutoRemoveCertainDuplicate($caseId))) === true;
            } catch (Throwable $e) {
                $failed++;
                $wasRemoved = false;

                $this->logger->warning('Duplicate results: automatic removal failed - the case stays open for the next run', [
                    'caseId' => $caseId,
                    'exception' => $e,
                ]);
            }

            $this->freshEntityManager();

            if ($wasRemoved === false) {
                continue;
            }

            $removed++;

            try {
                /** @var list<string> $nowCertain */
                $nowCertain = $this->resultOf($this->messageBus->dispatch(new ReclassifyDuplicateCasesAfterRemoval($caseId)));
                $queue = [...$queue, ...$nowCertain];
            } catch (Throwable $e) {
                $this->logger->warning('Duplicate results: re-classification after a removal failed - left for the next run', [
                    'caseId' => $caseId,
                    'exception' => $e,
                ]);
            }

            $this->freshEntityManager();
        }

        return $summary->withAutoRemovals($removed, $failed);
    }

    private function resultOf(Envelope $envelope): mixed
    {
        return $envelope->last(HandledStamp::class)?->getResult();
    }

    /**
     * A refused handler's changes are rolled back in the database but stay in the unit of work - the next message's
     * flush would write them after all. A failed flush closes the entity manager altogether.
     */
    private function freshEntityManager(): void
    {
        if ($this->entityManager->isOpen()) {
            $this->entityManager->clear();

            return;
        }

        $this->managerRegistry->resetManager();
    }
}
