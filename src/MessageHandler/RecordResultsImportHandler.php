<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ResultsImportLog;
use SpeedPuzzling\Web\Message\RecordResultsImport;
use SpeedPuzzling\Web\Repository\EventResultsSheetRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\ResultsImportLogRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RecordResultsImportHandler
{
    public function __construct(
        private EventResultsSheetRepository $sourceRepository,
        private PlayerRepository $playerRepository,
        private ResultsImportLogRepository $logRepository,
    ) {
    }

    public function __invoke(RecordResultsImport $message): void
    {
        $added = 0;
        $updated = 0;
        $removed = 0;

        foreach ($message->events as $event) {
            $added += $event['added'];
            $updated += $event['updated'];
            $removed += $event['removed'];
        }

        $this->logRepository->save(new ResultsImportLog(
            id: Uuid::fromString($message->importId),
            source: $this->sourceRepository->get($message->sourceId),
            checkId: Uuid::fromString($message->checkId),
            importedBy: $this->playerRepository->get($message->importedByPlayerId),
            importedAt: $message->importedAt,
            events: $message->events,
            teamsAdded: $added,
            teamsUpdated: $updated,
            teamsRemoved: $removed,
        ));
    }
}
