<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SuspiciousTimes;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\DetectSuspiciousTimes;
use SpeedPuzzling\Web\Message\NotifySuspiciousTimes;
use SpeedPuzzling\Web\Results\SuspiciousTimeScanResult;
use SpeedPuzzling\Web\Results\SuspiciousTimeScanSummary;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * One run of `myspeedpuzzling:detect-suspicious-times` (docs/features/suspicious-time-review.md, "Cron"): the scan,
 * then the notice run, each a top-level message in a transaction of its own - a failing one is logged (warning) and
 * never takes the other down. The entity manager is cleared between them (reset when a failed flush closed it), like
 * DailyDuplicateDetection. A dry run is the scan only.
 *
 * existingMarksToldByHand: once at go-live - the notices of this run are recorded as told by hand (NotifySuspiciousTimes).
 */
readonly final class SuspiciousTimeScan
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $managerRegistry,
        private LoggerInterface $logger,
    ) {
    }

    public function run(bool $dryRun = false, bool $existingMarksToldByHand = false): SuspiciousTimeScanResult
    {
        $detection = null;

        try {
            $detection = $this->messageBus->dispatch(new DetectSuspiciousTimes($dryRun))->last(HandledStamp::class)?->getResult();
            assert($detection instanceof SuspiciousTimeScanSummary);
        } catch (Throwable $e) {
            $detection = null;

            $this->logger->warning('Time verification: the scan failed - nothing of it was stored, the next run tries again', [
                'exception' => $e,
            ]);
        }

        $this->freshEntityManager();

        if ($dryRun) {
            return new SuspiciousTimeScanResult($detection, null);
        }

        try {
            $notices = $this->messageBus->dispatch(new NotifySuspiciousTimes(toldByHand: $existingMarksToldByHand))->last(HandledStamp::class)?->getResult();
            assert(is_int($notices));
        } catch (Throwable $e) {
            // At go-live the next plain run would tell the players about the marks they were e-mailed by hand
            $this->logger->warning($existingMarksToldByHand
                ? 'Time verification: the go-live notice run failed - nothing was recorded; run it again with --existing-marks-told-by-hand before any other run'
                : 'Time verification: the notice run failed - the next run tells the players', [
                    'exception' => $e,
                ]);
            $this->freshEntityManager();

            return new SuspiciousTimeScanResult($detection, null, noticesFailed: true);
        }

        $this->freshEntityManager();

        return new SuspiciousTimeScanResult($detection, $notices);
    }

    /**
     * One puzzle's times judged again right after a moderator changed its slow threshold - its cases close (or open)
     * at once. Null when it failed (logged): the next run judges them, the threshold made their checks stale.
     */
    public function forPuzzle(string $puzzleId): null|SuspiciousTimeScanSummary
    {
        try {
            $detection = $this->messageBus->dispatch(new DetectSuspiciousTimes(onlyPuzzleId: $puzzleId))->last(HandledStamp::class)?->getResult();
            assert($detection instanceof SuspiciousTimeScanSummary);
        } catch (Throwable $e) {
            $detection = null;

            $this->logger->warning('Time verification: judging one puzzle\'s times again failed - the next run does it', [
                'puzzleId' => $puzzleId,
                'exception' => $e,
            ]);
        }

        $this->freshEntityManager();

        return $detection;
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
