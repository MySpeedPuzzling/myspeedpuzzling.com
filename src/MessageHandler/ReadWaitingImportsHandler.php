<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\ReadImportFile;
use SpeedPuzzling\Web\Message\ReadWaitingImports;
use SpeedPuzzling\Web\Message\RecordImportReadFailed;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportReadFailure;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The reading's safety net (docs/features/data-import/reading.md "The safety net"):
 * - an import that rests in `uploaded` for more than DataImportLimits::UNREAD_AFTER_SECONDS (its message was lost,
 *   or it was uploaded before reading existed) is queued again - a second message for an import somebody reads
 *   meanwhile finds nothing to do;
 * - an import "being read" that wrote nothing for DataImportLimits::READING_STALLED_AFTER_SECONDS - its worker died
 *   or was killed by the time limit - is failed with the "our side" wording: the reading state always ends, and the
 *   player can try again from the page.
 */
#[AsMessageHandler]
readonly final class ReadWaitingImportsHandler
{
    private const int PER_RUN = 200;

    public function __construct(
        private DataImportRepository $dataImportRepository,
        private MessageBusInterface $messageBus,
        private ImportProgress $progress,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{queued: int, failed: int}
     */
    public function __invoke(ReadWaitingImports $message): array
    {
        $now = $this->clock->now();
        $queued = 0;
        $failed = 0;

        foreach ($this->dataImportRepository->idsWaitingToBeReadSince($now->modify('-' . DataImportLimits::UNREAD_AFTER_SECONDS . ' seconds'), self::PER_RUN) as $importId) {
            $this->messageBus->dispatch(new ReadImportFile($importId));
            $queued++;
        }

        $since = $now->modify('-' . DataImportLimits::READING_STALLED_AFTER_SECONDS . ' seconds');

        foreach ($this->dataImportRepository->idsReadingWithoutProgressSince($since, self::PER_RUN) as $importId) {
            $envelope = $this->messageBus->dispatch(new RecordImportReadFailed($importId, DataImportReadFailure::STALLED, withoutProgressSince: $since));

            if ($envelope->last(HandledStamp::class)?->getResult() !== true) {
                continue;
            }

            $failed++;
            $this->progress->publish($importId);
            $this->logger->warning('An import was being read without any progress for a quarter of an hour - its worker died; the player can try again', [
                'import_id' => $importId,
            ]);
        }

        return ['queued' => $queued, 'failed' => $failed];
    }
}
