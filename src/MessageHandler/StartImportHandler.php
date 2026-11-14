<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportUploadRefused;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\StartImport;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class StartImportHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private DataImportRepository $dataImportRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws DataImportNotFound the id is somebody else's import
     * @throws ImportUploadRefused
     */
    public function __invoke(StartImport $message): void
    {
        if (Uuid::isValid($message->importId) === false) {
            throw new DataImportNotFound();
        }

        $player = $this->playerRepository->get($message->playerId);
        // Two starts of one player at once must not both pass the count below
        $this->idLock->lockUntilCommit($player->id);

        $existing = $this->dataImportRepository->find($message->importId);

        if ($existing !== null) {
            if ($existing->isOwnedBy($player->id->toString()) === false) {
                throw new DataImportNotFound();
            }

            // The same start sent again
            return;
        }

        // Shown on the player's own pages only; never part of the object's key
        $fileName = mb_substr(trim(str_replace(["\r", "\n", "\t", "\0", '/', '\\'], ' ', $message->fileName)), 0, DataImportLimits::FILE_NAME_MAX_LENGTH);
        $kind = DataImportFileKind::ofFileName($fileName);

        if ($kind === null) {
            throw new ImportUploadRefused('type');
        }

        if ($message->fileSize < 1) {
            throw new ImportUploadRefused('empty');
        }

        if ($message->fileSize > DataImportLimits::MAX_FILE_BYTES) {
            throw new ImportUploadRefused('too_large');
        }

        if ($this->dataImportRepository->countUnfinishedOf($player) >= DataImportLimits::MAX_UNFINISHED_PER_PLAYER) {
            throw new ImportUploadRefused('too_many');
        }

        $this->dataImportRepository->save(DataImport::uploadOfFile(
            id: Uuid::fromString($message->importId),
            player: $player,
            fileName: $fileName,
            fileKind: $kind,
            fileSize: $message->fileSize,
            fileKey: ImportFileStorage::objectKey($player->id->toString(), $message->importId, $kind),
            now: $this->clock->now(),
        ));
    }
}
