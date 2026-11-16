<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\EventResultsSheetAlreadyRegistered;
use SpeedPuzzling\Web\Exceptions\GoogleSheetUnreadable;
use SpeedPuzzling\Web\Message\ChangeResultsSourceSheet;
use SpeedPuzzling\Web\Message\RecordResultsSourceSheetChange;
use SpeedPuzzling\Web\Repository\EventResultsSheetRepository;
use SpeedPuzzling\Web\Services\EventResultsImport\GoogleSheetReader;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultsSourceSheetChange;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Reads only - outside a transaction while Google answers (HandledOutsideTransaction) - and writes through
 * RecordResultsSourceSheetChange. A refusal changes nothing, not even a check: the page keeps showing the sheet it had.
 */
#[AsMessageHandler]
readonly final class ChangeResultsSourceSheetHandler
{
    public function __construct(
        private EventResultsSheetRepository $sourceRepository,
        private GoogleSheetReader $reader,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ChangeResultsSourceSheet $message): ResultsSourceSheetChange
    {
        $source = $this->sourceRepository->get($message->sourceId);

        if ($source->spreadsheetId === $message->spreadsheetId) {
            return ResultsSourceSheetChange::Same;
        }

        if ($this->sourceRepository->findBySpreadsheetId($message->spreadsheetId) !== null) {
            return ResultsSourceSheetChange::Taken;
        }

        // The read's start, like a check's: an import from it refuses what was applied after it
        $readAt = $this->clock->now();

        try {
            $snapshot = $this->reader->snapshot($message->spreadsheetId);
        } catch (GoogleSheetUnreadable $e) {
            // The page says it; the organizer fixes the sharing or the link - nobody else has to look
            $this->logger->info('Results source {source}: the new spreadsheet could not be read: {error}', [
                'source' => $message->sourceId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return ResultsSourceSheetChange::Unreadable;
        }

        try {
            $this->messageBus->dispatch(new RecordResultsSourceSheetChange(
                checkId: $message->checkId,
                sourceId: $message->sourceId,
                spreadsheetId: $message->spreadsheetId,
                changedByPlayerId: $message->changedByPlayerId,
                readAt: $readAt,
                snapshot: $snapshot,
            ));
        } catch (HandlerFailedException $e) {
            // Another source took it while Google answered
            if ($e->getWrappedExceptions(EventResultsSheetAlreadyRegistered::class) !== []) {
                return ResultsSourceSheetChange::Taken;
            }

            throw $e;
        } catch (UniqueConstraintViolationException) {
            return ResultsSourceSheetChange::Taken;
        }

        return ResultsSourceSheetChange::Changed;
    }
}
