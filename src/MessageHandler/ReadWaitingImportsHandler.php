<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\ReadWaitingImports;
use SpeedPuzzling\Web\Message\RecordImportFetchFailed;
use SpeedPuzzling\Web\Message\RecordImportReadFailed;
use SpeedPuzzling\Web\Message\RequestImportRead;
use SpeedPuzzling\Web\Message\RequestImportSetUp;
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
 *   or it was uploaded before reading existed) is queued again (RequestImportRead stamps `read_requested_at`): once
 *   asked for, it is asked for again only after DataImportLimits::READ_REQUESTED_AGAIN_AFTER_SECONDS - never a
 *   second message on every run;
 * - an import "being read" that wrote nothing for DataImportLimits::READING_STALLED_AFTER_SECONDS - its worker died
 *   or was killed by the time limit - is failed with the "our side" wording: the reading state always ends, and the
 *   player can try again from the page;
 * - a Google sheet "being fetched" without any news for DataImportLimits::FETCHING_STALLED_AFTER_SECONDS - its message
 *   was lost, or its worker died (a fetch itself never takes more than a minute) - is failed the same way: the
 *   fetching state always ends too (docs/features/data-import/google-sheets.md);
 * - a read import one of whose sheets still waits for its mapping or its parse, with nothing written for the same
 *   quarter of an hour, gets that step queued again (RequestImportSetUp).
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

        $waiting = $this->dataImportRepository->idsWaitingToBeRead(
            $now->modify('-' . DataImportLimits::UNREAD_AFTER_SECONDS . ' seconds'),
            $now->modify('-' . DataImportLimits::READ_REQUESTED_AGAIN_AFTER_SECONDS . ' seconds'),
            self::PER_RUN,
        );

        foreach ($waiting as $importId) {
            // Stamped and queued in one transaction - the next runs leave it alone for a quarter of an hour
            if ($this->messageBus->dispatch(new RequestImportRead($importId))->last(HandledStamp::class)?->getResult() === true) {
                $queued++;
            }
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

        // Read, but a sheet still waits for its mapping or its parse and nothing moved for a quarter of an hour: the
        // message was lost - or the import was read before these steps existed (docs/features/data-import/columns.md)
        // Tried again for a week after the read - a step that fails every time must not be queued for ever
        $readAfter = $now->modify('-' . DataImportLimits::SET_UP_RETRIED_FOR_DAYS . ' days');

        foreach ($this->dataImportRepository->idsSetUpStandingSince($since, $readAfter, self::PER_RUN) as $importId) {
            if ($this->messageBus->dispatch(new RequestImportSetUp($importId))->last(HandledStamp::class)?->getResult() === true) {
                $queued++;
            }
        }

        $since = $now->modify('-' . DataImportLimits::FETCHING_STALLED_AFTER_SECONDS . ' seconds');

        foreach ($this->dataImportRepository->idsFetchingWithoutProgressSince($since, self::PER_RUN) as $importId) {
            $envelope = $this->messageBus->dispatch(new RecordImportFetchFailed($importId, DataImportReadFailure::STALLED, withoutProgressSince: $since));

            if ($envelope->last(HandledStamp::class)?->getResult() !== true) {
                continue;
            }

            $failed++;
            $this->progress->publish($importId);
            $this->logger->warning('An import\'s Google sheet was being fetched without any news for a quarter of an hour - its message was lost or its worker died; the player can try again', [
                'import_id' => $importId,
            ]);
        }

        return ['queued' => $queued, 'failed' => $failed];
    }
}
