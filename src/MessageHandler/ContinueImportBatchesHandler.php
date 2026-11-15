<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\ContinueImportBatches;
use SpeedPuzzling\Web\Message\ProcessUnlistedPairing;
use SpeedPuzzling\Web\Message\RecordImportGroupAnswer;
use SpeedPuzzling\Web\Message\QueueImportTurn;
use SpeedPuzzling\Web\Entity\DataImportPileAnswer;
use SpeedPuzzling\Web\Message\RecordImportPuzzlePileAnswer;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Repository\DataImportPileAnswerRepository;
use SpeedPuzzling\Web\Repository\DataImportSettingsRepository;
use SpeedPuzzling\Web\Repository\UnlistedPuzzleRepository;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The sweeper (docs/features/data-import/adding.md "The work"): a running "Add" or Undo nobody worked on for
 * DataImportLimits::BATCH_STANDING_AFTER_SECONDS is handed to the `imports` worker again. A job sent once too often
 * finds nothing to do - everything is driven by the rows - and there is one chain of turns however often it is asked
 * for (QueueImportTurn). A batch counts as alive from the moment a turn of it is queued or begun. While adding is
 * paused only Undos are continued - and batches an Undo stopped, which have nothing left to add.
 */
#[AsMessageHandler]
readonly final class ContinueImportBatchesHandler
{
    private const int AT_MOST = 100;
    /** A group answer without a sign of life for so long is ended as failed */
    public const int GROUP_ANSWER_STANDING_AFTER_SECONDS = 900;

    public function __construct(
        private DataImportBatchRepository $batchRepository,
        private DataImportSettingsRepository $settingsRepository,
        private UnlistedPuzzleRepository $unlistedRepository,
        private DataImportPileAnswerRepository $pileAnswerRepository,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{batches: int, undos: int, answers: int, pairings: int}
     */
    public function __invoke(ContinueImportBatches $message): array
    {
        $since = $this->clock->now()->modify(sprintf('-%d seconds', DataImportLimits::BATCH_STANDING_AFTER_SECONDS));
        $batchIds = $this->batchRepository->idsRunningStandingSince($since, self::AT_MOST, onlyStopped: $this->settingsRepository->isAddingPaused());
        $undoIds = $this->batchRepository->undoIdsRunningStandingSince($since, self::AT_MOST);
        // Pairings of unlisted puzzles (docs/features/data-import/unlisted-puzzles.md) go the same way: every step
        // reads its subject's state under the player's lock, so a job sent once too often finds nothing to do
        $pairingIds = $this->unlistedRepository->pairingIdsStandingSince($since, self::AT_MOST, onlyUndos: $this->settingsRepository->isAddingPaused());

        // A new turn each: the number is raised and the moment noted, so a job of the old turn that only waited in a
        // long queue ends when it comes, and the next sweep leaves the batch alone
        foreach ($batchIds as $batchId) {
            $this->messageBus->dispatch(QueueImportTurn::ofBatch($batchId));
        }

        foreach ($undoIds as $undoId) {
            $this->messageBus->dispatch(QueueImportTurn::ofUndo($undoId));
        }

        foreach ($pairingIds as $pairingId) {
            $this->messageBus->dispatch(new ProcessUnlistedPairing($pairingId));
        }

        // A group answer that stands still is not continued but ended: what it confirmed stays, the rest can be
        // answered again - it must not hold its reason for ever (docs/features/data-import/group-answers.md)
        $answerIds = $this->batchRepository->groupAnswerIdsRunningStandingSince(
            $this->clock->now()->modify(sprintf('-%d seconds', self::GROUP_ANSWER_STANDING_AFTER_SECONDS)),
            self::AT_MOST,
        );

        foreach ($answerIds as $answerId) {
            $this->messageBus->dispatch(new RecordImportGroupAnswer($answerId, failed: true));
            $this->logger->warning('An import\'s group answer stood still and was ended as failed', ['answer_id' => $answerId]);
        }

        // One answer for a whole pile of puzzles (docs/features/data-import/guided-flow.md §6.3) the same way: what it
        // decided stays, the rest of the pile waits and can be answered again
        $standing = $this->clock->now()->modify(sprintf('-%d seconds', self::GROUP_ANSWER_STANDING_AFTER_SECONDS));

        foreach ($this->pileAnswerRepository->idsOpenStandingSince($standing, self::AT_MOST) as $pileAnswerId) {
            $this->messageBus->dispatch(new RecordImportPuzzlePileAnswer($pileAnswerId, endedAs: DataImportPileAnswer::FAILED));
            $this->logger->warning('An answer for a pile of an import\'s puzzles stood still and was ended', ['answer_id' => $pileAnswerId]);
        }

        return ['batches' => count($batchIds), 'undos' => count($undoIds), 'answers' => count($answerIds), 'pairings' => count($pairingIds)];
    }
}
