<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ResultsImportCheck;
use SpeedPuzzling\Web\Message\RecordResultsImportCheck;
use SpeedPuzzling\Web\Repository\EventResultsSheetRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\ResultsImportCheckRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Keeps the check and drops the source's checks older than the newest KEEP - each holds the whole sheet. An import log
 * keeps the id of the check it came from (no foreign key).
 */
#[AsMessageHandler]
readonly final class RecordResultsImportCheckHandler
{
    public const int KEEP = 10;

    public function __construct(
        private EventResultsSheetRepository $sourceRepository,
        private PlayerRepository $playerRepository,
        private ResultsImportCheckRepository $checkRepository,
    ) {
    }

    public function __invoke(RecordResultsImportCheck $message): void
    {
        $source = $this->sourceRepository->get($message->sourceId);
        $player = $this->playerRepository->get($message->checkedByPlayerId);
        $id = Uuid::fromString($message->checkId);

        $check = $message->snapshot !== null
            ? ResultsImportCheck::read($id, $source, $player, $message->checkedAt, $message->snapshot)
            : ResultsImportCheck::failed($id, $source, $player, $message->checkedAt, $message->readError ?? '');

        // The newest KEEP - 1 stored ones + this one stay
        foreach ($this->checkRepository->olderThanLatest($source, self::KEEP - 1) as $old) {
            $this->checkRepository->delete($old);
        }

        $this->checkRepository->save($check);
    }
}
