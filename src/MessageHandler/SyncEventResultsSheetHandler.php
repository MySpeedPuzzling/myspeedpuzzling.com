<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\GoogleSheetUnreadable;
use SpeedPuzzling\Web\Message\ApplyEventResultsTab;
use SpeedPuzzling\Web\Message\RecordEventResultsSheetFetch;
use SpeedPuzzling\Web\Message\SyncEventResultsSheet;
use SpeedPuzzling\Web\Repository\EventResultsSheetRepository;
use SpeedPuzzling\Web\Services\EventResultsImport\ResultsSourceReader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Reads and dispatches only - outside a transaction while Google answers (HandledOutsideTransaction). The source's
 * format makes the events of the sheet; every event is applied by ApplyEventResultsTab in a transaction of its own, so
 * one broken tab never holds back the others. A tab that disappeared from the sheet is left as it is: its event and
 * results stay.
 */
#[AsMessageHandler]
readonly final class SyncEventResultsSheetHandler
{
    public function __construct(
        private EventResultsSheetRepository $sheetRepository,
        private ResultsSourceReader $sourceReader,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncEventResultsSheet $message): void
    {
        $sheet = $this->sheetRepository->get($message->sheetId);
        $spreadsheetId = $sheet->spreadsheetId;
        $previousError = $sheet->lastFetchError;
        $errors = [];

        try {
            $read = $this->sourceReader->read($spreadsheetId, $sheet->format);
        } catch (GoogleSheetUnreadable $e) {
            $this->recordFetch($message->sheetId, $e->getMessage(), $previousError, $e);

            return;
        }

        foreach ($read->snapshot->unreadableTabs as $tab) {
            $errors[] = sprintf('Tab "%s": %s', $tab->title, $tab->reason);
        }

        foreach ($read->events as $event) {
            $this->messageBus->dispatch(new ApplyEventResultsTab($message->sheetId, $event));
        }

        $this->recordFetch($message->sheetId, $errors === [] ? null : implode("\n", $errors), $previousError);
    }

    private function recordFetch(string $sheetId, null|string $error, null|string $previousError, null|GoogleSheetUnreadable $exception = null): void
    {
        $this->messageBus->dispatch(new RecordEventResultsSheetFetch($sheetId, $error));

        // Once when it starts failing, not every hourly run while Google or the sharing setting stays the same
        if ($error !== null && $error !== $previousError) {
            $this->logger->warning('Event results sheet {sheet} could not be read: {error}', [
                'sheet' => $sheetId,
                'error' => $error,
                'exception' => $exception,
            ]);
        }
    }
}
