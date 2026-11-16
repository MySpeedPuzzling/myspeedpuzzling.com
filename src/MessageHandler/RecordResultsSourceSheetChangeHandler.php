<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ResultsImportCheck;
use SpeedPuzzling\Web\Exceptions\EventResultsSheetAlreadyRegistered;
use SpeedPuzzling\Web\Message\RecordResultsSourceSheetChange;
use SpeedPuzzling\Web\Repository\EventResultsSheetRepository;
use SpeedPuzzling\Web\Repository\EventResultsSheetTabRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\ResultsImportCheckRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The source reads the new spreadsheet from now on, and the read that proved it readable is its only check: every
 * older check was of the previous sheet and is dropped - an import is made only from the latest check, which must
 * never be the previous sheet's. Every stored item is set aside under the previous-sheet prefix in the same
 * transaction (EventResultsSheetTab::setAsideForPreviousSheet()) - a key of the new sheet never meets another event's
 * item. The info log says who changed it, from which to which.
 */
#[AsMessageHandler]
readonly final class RecordResultsSourceSheetChangeHandler
{
    public function __construct(
        private EventResultsSheetRepository $sourceRepository,
        private EventResultsSheetTabRepository $tabRepository,
        private PlayerRepository $playerRepository,
        private ResultsImportCheckRepository $checkRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws EventResultsSheetAlreadyRegistered another source reads it
     */
    public function __invoke(RecordResultsSourceSheetChange $message): void
    {
        $source = $this->sourceRepository->get($message->sourceId);
        $taken = $this->sourceRepository->findBySpreadsheetId($message->spreadsheetId);

        if ($taken !== null && $taken->id->equals($source->id) === false) {
            throw new EventResultsSheetAlreadyRegistered();
        }

        $player = $this->playerRepository->get($message->changedByPlayerId);
        $previous = $source->spreadsheetId;
        $source->changeSpreadsheet($message->spreadsheetId);

        // The new sheet's keys belong to its own tabs (gid 0 is any new spreadsheet's first tab): every stored item is
        // set aside under the previous-sheet prefix - only its date and name connect it to the new sheet from now on
        $items = $this->tabRepository->ofSheet($source);
        $takenKeys = [];

        foreach ($items as $item) {
            $takenKeys[$item->gid] = true;
        }

        $setAside = 0;

        foreach ($items as $item) {
            if ($item->isFromPreviousSheet() === false) {
                $takenKeys[$item->setAsideForPreviousSheet($takenKeys)] = true;
                $setAside++;
            }
        }

        foreach ($this->checkRepository->olderThanLatest($source, 0) as $old) {
            $this->checkRepository->delete($old);
        }

        $this->checkRepository->save(ResultsImportCheck::read(Uuid::fromString($message->checkId), $source, $player, $message->readAt, $message->snapshot));

        // No approval needed - counted in Loki, nobody has to look
        $this->logger->info('Results source {source}: spreadsheet changed by {player} from {from} to {to}', [
            'source' => $source->id->toString(),
            'player' => $message->changedByPlayerId,
            'from' => $previous,
            'to' => $message->spreadsheetId,
            'set_aside' => $setAside,
        ]);
    }
}
