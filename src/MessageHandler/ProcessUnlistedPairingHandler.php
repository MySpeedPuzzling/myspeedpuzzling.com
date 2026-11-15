<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\ApplyUnlistedPairingStep;
use SpeedPuzzling\Web\Message\ProcessUnlistedPairing;
use SpeedPuzzling\Web\Repository\DataImportSettingsRepository;
use SpeedPuzzling\Web\Repository\UnlistedPuzzleRepository;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\Unlisted\UnlistedPairingState;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * A turn of a pairing of an unlisted puzzle, or of its Undo (docs/features/data-import/unlisted-puzzles.md
 * "Pairing"). Outside a transaction: every result and every mark is a message of its own, handled at once in its own
 * short transaction (ApplyUnlistedPairingStep), the results first. After DataImportLimits::ROWS_PER_TURN subjects or
 * SECONDS_PER_TURN the message sends itself again, so other players' jobs take their turns in between.
 *
 * Everything is read from the subjects' state, under the player's lock inside each step: a second delivery, a
 * restart or a second worker repeats nothing. A step that throws is rolled back and its subject marked - it stays
 * unlisted (or, for an Undo, real) with the reason - and the pairing goes on. **The safety switch is asked before
 * every step of a pairing** (never of an Undo): a paused pairing waits for the sweeper. So does a pairing that used
 * today's guard on results up (UnlistedPairer::result()): its marks still go, its results wait for tomorrow.
 */
#[AsMessageHandler]
readonly final class ProcessUnlistedPairingHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private UnlistedPuzzleRepository $unlistedRepository,
        private DataImportSettingsRepository $settingsRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessUnlistedPairing $message): void
    {
        // As the database has it now: this job may have waited, and the entity manager may hold an older pairing
        $this->entityManager->clear();
        $pairing = $this->unlistedRepository->findPairing($message->pairingId);

        if ($pairing === null || $pairing->isWorking() === false) {
            return;
        }

        $undoing = $pairing->state === UnlistedPairingState::Undoing;
        $startedAt = hrtime(true);
        $done = 0;
        $firstFailure = null;
        // Today's guard on results from spreadsheets is used up: the results that are left wait for tomorrow
        $resultsWait = false;

        while ($resultsWait === false && $done < DataImportLimits::ROWS_PER_TURN && (hrtime(true) - $startedAt) / 1_000_000_000 < DataImportLimits::SECONDS_PER_TURN) {
            $pending = $this->unlistedRepository->pendingOfPairing($message->pairingId, $undoing, DataImportLimits::ROWS_PER_TURN - $done);

            if ($pending === []) {
                $this->finish($message->pairingId);

                return;
            }

            foreach ($pending as $subject) {
                if ($resultsWait && $subject['kind'] === ApplyUnlistedPairingStep::RESULT) {
                    continue;
                }

                if ($undoing === false && $this->settingsRepository->isAddingPaused()) {
                    // The pairing waits; myspeedpuzzling:continue-import-batches goes on once adding is on again
                    return;
                }

                $done++;

                try {
                    $stepDone = $this->messageBus->dispatch(new ApplyUnlistedPairingStep($message->pairingId, $subject['kind'], $subject['id']))->last(HandledStamp::class)?->getResult();
                    // The marks of the same turn still go: only results use the day up
                    $resultsWait = $resultsWait || $stepDone === false;
                } catch (\Throwable $e) {
                    $firstFailure ??= $e;
                    // The rolled-back step left its changes in the entity manager
                    $this->entityManager->clear();

                    try {
                        $this->messageBus->dispatch(new ApplyUnlistedPairingStep($message->pairingId, $subject['kind'], $subject['id'], failed: true));
                    } catch (\Throwable $markFailure) {
                        // Left for the sweeper: nothing in this turn would do better
                        $this->entityManager->clear();
                        $this->logger->error('A failed step of an unlisted puzzle\'s pairing could not be marked', [
                            'pairing_id' => $message->pairingId,
                            'exception' => $markFailure,
                        ]);

                        return;
                    }
                }
            }
        }

        if ($firstFailure !== null) {
            // Ids only - never a name or a value of the player's file
            $this->logger->error('Steps of an unlisted puzzle\'s pairing failed - their subjects are marked, the pairing goes on', [
                'pairing_id' => $message->pairingId,
                'exception' => $firstFailure,
            ]);
        }

        if ($resultsWait) {
            // The pairing stays at work and is not queued again now: myspeedpuzzling:continue-import-batches asks
            // every few minutes, and goes on with it once the day has turned
            return;
        }

        // The next turn - behind whatever else waits on the `imports` queue
        $this->messageBus->dispatch(new ProcessUnlistedPairing($message->pairingId));
    }

    private function finish(string $pairingId): void
    {
        try {
            $finished = $this->messageBus->dispatch(new ApplyUnlistedPairingStep($pairingId, ApplyUnlistedPairingStep::FINISH))->last(HandledStamp::class)?->getResult();
        } catch (\Throwable $e) {
            // The pairing stays at work; the sweeper tries again
            $this->entityManager->clear();
            $this->logger->error('An unlisted puzzle\'s pairing could not be finished - the sweeper tries again', [
                'pairing_id' => $pairingId,
                'exception' => $e,
            ]);

            return;
        }

        if ($finished === false) {
            $this->messageBus->dispatch(new ProcessUnlistedPairing($pairingId));
        }
    }
}
