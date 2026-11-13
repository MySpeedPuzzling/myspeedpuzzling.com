<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\GoogleSheetUnreadable;
use SpeedPuzzling\Web\Message\ApplyEventResultsTab;
use SpeedPuzzling\Web\Message\RecordEventResultsSheetFetch;
use SpeedPuzzling\Web\Message\SyncEventResultsSheet;
use SpeedPuzzling\Web\Repository\EventResultsSheetRepository;
use SpeedPuzzling\Web\Services\EventResultsImport\SarahDoesPuzzlesTabParser;
use SpeedPuzzling\Web\Services\EventResultsImport\GoogleSheetReader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Reads and dispatches only - outside a transaction while Google answers (HandledOutsideTransaction); every tab is
 * applied by ApplyEventResultsTab in a transaction of its own, so one broken tab never holds back the others. A tab
 * that disappeared from the sheet is left as it is: its event and results stay.
 */
#[AsMessageHandler]
readonly final class SyncEventResultsSheetHandler
{
    public function __construct(
        private EventResultsSheetRepository $sheetRepository,
        private GoogleSheetReader $reader,
        private SarahDoesPuzzlesTabParser $parser,
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
            $tabs = $this->reader->tabs($spreadsheetId);
        } catch (GoogleSheetUnreadable $e) {
            $this->recordFetch($message->sheetId, $e->getMessage(), $previousError, $e);

            return;
        }

        foreach ($tabs as $tab) {
            try {
                $csv = $this->reader->csv($spreadsheetId, $tab->gid);
            } catch (GoogleSheetUnreadable $e) {
                $errors[] = sprintf('Tab "%s": %s', $tab->title, $e->getMessage());

                continue;
            }

            $this->messageBus->dispatch(new ApplyEventResultsTab($message->sheetId, $tab->gid, $tab->title, $this->parser->parse($csv)));
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
