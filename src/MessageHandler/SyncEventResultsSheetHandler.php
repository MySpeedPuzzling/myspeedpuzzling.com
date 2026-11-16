<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\GoogleSheetUnreadable;
use SpeedPuzzling\Web\Message\ApplyEventResultsTab;
use SpeedPuzzling\Web\Message\RecordEventResultsSheetFetch;
use SpeedPuzzling\Web\Message\RemoveUnimportedResultsSourceItem;
use SpeedPuzzling\Web\Message\SyncEventResultsSheet;
use SpeedPuzzling\Web\Query\GetResultsImportStoredTabs;
use SpeedPuzzling\Web\Repository\EventResultsSheetRepository;
use SpeedPuzzling\Web\Services\EventResultsImport\PreviousSheetEvents;
use SpeedPuzzling\Web\Services\EventResultsImport\ResultsSourceReader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Reads and dispatches only - outside a transaction while Google answers (HandledOutsideTransaction). The source's
 * format makes the events of the sheet; every event is applied by ApplyEventResultsTab in a transaction of its own, so
 * one broken tab never holds back the others. A tab that disappeared from the sheet is left as it is: its event and
 * results stay. An event imported from the previous spreadsheet under another key moves to its new key
 * (PreviousSheetEvents); an uncertain match is not applied and recorded as the fetch's error.
 */
#[AsMessageHandler]
readonly final class SyncEventResultsSheetHandler
{
    public function __construct(
        private EventResultsSheetRepository $sheetRepository,
        private ResultsSourceReader $sourceReader,
        private GetResultsImportStoredTabs $getStoredTabs,
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

        // After a change of the spreadsheet: the events imported from the previous one, matched by date and name
        $previous = PreviousSheetEvents::match($read->events, $this->getStoredTabs->ofSource($message->sheetId));

        foreach ($read->events as $event) {
            $match = $previous[$event->key] ?? null;

            if ($match?->problem !== null) {
                // Never a guess - the results import page shows it as "Needs fixing" until the organizer renames one
                $errors[] = sprintf('Tab "%s": the same date and name as other events (%s) - not applied', $event->title, $match->problem);

                continue;
            }

            if ($match !== null && $match->replacesUnimportedItem) {
                $this->messageBus->dispatch(new RemoveUnimportedResultsSourceItem($message->sheetId, $event->key));
            }

            $this->messageBus->dispatch(new ApplyEventResultsTab($message->sheetId, $event, replacesKey: $match?->storedKey));
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
