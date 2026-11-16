<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\GoogleSheetUnreadable;
use SpeedPuzzling\Web\Message\CheckResultsSource;
use SpeedPuzzling\Web\Message\RecordResultsImportCheck;
use SpeedPuzzling\Web\Repository\EventResultsSheetRepository;
use SpeedPuzzling\Web\Services\EventResultsImport\GoogleSheetReader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Reads only - outside a transaction while Google answers (HandledOutsideTransaction), then writes the check through
 * RecordResultsImportCheck. The sheet is kept as read (every tab's CSV); the page parses it through the source's format.
 */
#[AsMessageHandler]
readonly final class CheckResultsSourceHandler
{
    public function __construct(
        private EventResultsSheetRepository $sourceRepository,
        private GoogleSheetReader $reader,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CheckResultsSource $message): void
    {
        $spreadsheetId = $this->sourceRepository->get($message->sourceId)->spreadsheetId;
        // The read's start: an import from this check refuses what was applied after it - erring on the refusing side
        $readStartedAt = $this->clock->now();
        $snapshot = null;
        $error = null;

        try {
            $snapshot = $this->reader->snapshot($spreadsheetId);
        } catch (GoogleSheetUnreadable $e) {
            // The page says it; the organizer fixes the sharing or tries again - nobody else has to look
            $this->logger->info('Results source {source} could not be read for a check: {error}', [
                'source' => $message->sourceId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            $error = $e->getMessage();
        }

        $this->messageBus->dispatch(new RecordResultsImportCheck(
            checkId: $message->checkId,
            sourceId: $message->sourceId,
            checkedByPlayerId: $message->checkedByPlayerId,
            checkedAt: $readStartedAt,
            snapshot: $snapshot,
            readError: $error,
        ));
    }
}
