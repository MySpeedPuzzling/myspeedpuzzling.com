<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\DataImportSource;
use SpeedPuzzling\Web\Value\DataImportStatus;

/**
 * One spreadsheet of one player (docs/features/data-import/README.md "Data model", docs/features/data-import/upload.md).
 * Private: every read and write takes the signed-in player's id, another player's import is "not found".
 *
 * The file itself is in object storage under `fileKey` (never served) for DataImportLimits::KEEP_FILE_DAYS; after
 * that - or when the import is discarded - the key is emptied and `fileRemovedAt` says when. The import stays.
 */
#[Entity]
#[Index(columns: ['player_id', 'created_at'])]
class DataImport
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 16, enumType: DataImportStatus::class)]
    public DataImportStatus $status;

    /** Set by the worker once it has read the file - "the same file again" is answered then */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 64, nullable: true)]
    public null|string $fileSha256 = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $fileRemovedAt = null;

    #[Immutable]
    #[Column(length: 100, nullable: true)]
    public null|string $googleSpreadsheetId = null;

    /** Reading the same source again raises it */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column]
    public int $version = 1;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $readError = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $uploadedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $lastActivityAt;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $discardedAt = null;

    /** "Forget the spreadsheet's content": the rows' cells are gone, their keys, states and outcomes stay */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $cellsWipedAt = null;

    private function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        #[Immutable]
        #[Column(length: 16, enumType: DataImportSource::class)]
        public DataImportSource $source,
        #[Immutable]
        #[Column(type: Types::TEXT)]
        public string $fileName,
        #[Immutable]
        #[Column(length: 8, nullable: true, enumType: DataImportFileKind::class)]
        public null|DataImportFileKind $fileKind,
        /** The size the upload was signed for - the object must have exactly this many bytes */
        #[Immutable]
        #[Column(type: Types::BIGINT, nullable: true)]
        public null|int $fileSize,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $fileKey,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
        $this->status = DataImportStatus::Uploading;
        $this->lastActivityAt = $createdAt;
    }

    /**
     * A file the browser is about to put under `$fileKey` - nothing has arrived yet
     */
    public static function uploadOfFile(
        UuidInterface $id,
        Player $player,
        string $fileName,
        DataImportFileKind $fileKind,
        int $fileSize,
        string $fileKey,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $player, DataImportSource::File, $fileName, $fileKind, $fileSize, $fileKey, $now);
    }

    public function isOwnedBy(string $playerId): bool
    {
        return $this->player->id->toString() === $playerId;
    }

    /**
     * The file is in object storage with the size it was signed for
     */
    public function uploadConfirmed(DateTimeImmutable $now): void
    {
        if ($this->status !== DataImportStatus::Uploading) {
            return;
        }

        $this->status = DataImportStatus::Uploaded;
        $this->uploadedAt = $now;
        $this->lastActivityAt = $now;
    }

    public function discard(DateTimeImmutable $now): void
    {
        if ($this->status === DataImportStatus::Discarded) {
            return;
        }

        $this->status = DataImportStatus::Discarded;
        $this->discardedAt = $now;
        $this->lastActivityAt = $now;
    }

    /**
     * The object is gone from storage (kept long enough, or the import was discarded) - the import itself stays
     */
    public function fileRemoved(DateTimeImmutable $now): void
    {
        if ($this->fileKey === null) {
            return;
        }

        $this->fileKey = null;
        $this->fileRemovedAt = $now;
    }
}
