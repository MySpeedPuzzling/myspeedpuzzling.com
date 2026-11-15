<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportUploadRefused;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\CreateImportFromGoogleSheet;
use SpeedPuzzling\Web\Message\StartImportFromGoogleSheet;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Services\DataImport\ImportSlots;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\GoogleSheetLink;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class CreateImportFromGoogleSheetHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private DataImportRepository $dataImportRepository,
        private ImportSlots $importSlots,
        private MessageBusInterface $messageBus,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws DataImportNotFound the id is somebody else's import
     * @throws ImportUploadRefused `type`: not a document's id; `too_many`: the player's unfinished imports
     */
    public function __invoke(CreateImportFromGoogleSheet $message): void
    {
        if (Uuid::isValid($message->importId) === false) {
            throw new DataImportNotFound();
        }

        if (GoogleSheetLink::isSpreadsheetId($message->spreadsheetId) === false) {
            throw new ImportUploadRefused('type');
        }

        $player = $this->playerRepository->get($message->playerId);
        // The lock of every start of the player (StartImportHandler): two at once must not both pass the count below
        $this->idLock->lockUntilCommit($player->id);

        $existing = $this->dataImportRepository->find($message->importId);

        if ($existing !== null) {
            if ($existing->isOwnedBy($player->id->toString()) === false) {
                throw new DataImportNotFound();
            }

            // The same form sent again
            return;
        }

        if ($this->importSlots->isFullFor($player->id->toString())) {
            throw new ImportUploadRefused('too_many');
        }

        $this->dataImportRepository->save(DataImport::ofGoogleSheet(
            id: Uuid::fromString($message->importId),
            player: $player,
            spreadsheetId: $message->spreadsheetId,
            fileKey: ImportFileStorage::objectKey($player->id->toString(), $message->importId, DataImportFileKind::Xlsx),
            now: $this->clock->now(),
        ));

        // Queued on the same connection as this transaction: the message exists exactly when the import does (one
        // that gets lost all the same ends as failed by myspeedpuzzling:read-waiting-imports - "Try again")
        $this->messageBus->dispatch(new StartImportFromGoogleSheet($message->importId));
    }
}
