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

    /** The worker took the file for reading - a read that never ends is seen by this (ReadWaitingImportsHandler) */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $readingStartedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $readFinishedAt = null;

    /**
     * Raised every time reading starts: what a read writes carries its number, so a read that was given up (the
     * worker hung, the player asked to try again) can not write into the next one.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $readAttempt = 0;

    /** Known once the file was opened */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $sheetsTotal = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $sheetsRead = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $rowsRead = 0;

    /**
     * Set on an import that was closed because the player already has one of the very same bytes: that one
     * (docs/features/data-import/reading.md "The same file again"). A plain id - the other import may go.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $sameFileAsImportId = null;

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

    /**
     * The worker takes the file. Only an import that waits for it - uploaded, or asked to be read again.
     *
     * @return bool false: nothing to read (being read already, read, discarded, the file gone)
     */
    public function readingStarted(DateTimeImmutable $now): bool
    {
        if ($this->status !== DataImportStatus::Uploaded || $this->fileKey === null) {
            return false;
        }

        $this->status = DataImportStatus::Reading;
        $this->readAttempt++;
        $this->readingStartedAt = $now;
        $this->readFinishedAt = null;
        $this->readError = null;
        $this->sheetsTotal = null;
        $this->sheetsRead = 0;
        $this->rowsRead = 0;
        $this->lastActivityAt = $now;

        return true;
    }

    /**
     * Whether what the read of this number found may still be written.
     */
    public function isBeingReadBy(int $readAttempt): bool
    {
        return $this->status === DataImportStatus::Reading && $this->readAttempt === $readAttempt;
    }

    public function fileHashed(string $sha256): void
    {
        $this->fileSha256 = $sha256;
    }

    /**
     * How far reading is - written through Services\DataImport\ImportProgress only.
     */
    public function readSoFar(null|int $sheetsTotal, int $sheetsRead, int $rowsRead, DateTimeImmutable $now): void
    {
        $this->sheetsTotal = $sheetsTotal;
        $this->sheetsRead = $sheetsRead;
        $this->rowsRead = $rowsRead;
        $this->lastActivityAt = $now;
    }

    public function readingFinished(DateTimeImmutable $now): void
    {
        if ($this->status !== DataImportStatus::Reading) {
            return;
        }

        $this->status = DataImportStatus::SettingUp;
        $this->readFinishedAt = $now;
        $this->lastActivityAt = $now;
    }

    /**
     * @param string $reason a SpreadsheetUnreadableReason's value (what the file is or holds), or
     *        DataImportReadFailure::OUR_SIDE / ::STALLED (nothing the file is to blame for)
     */
    public function readingFailed(string $reason, DateTimeImmutable $now): void
    {
        if ($this->status !== DataImportStatus::Reading) {
            return;
        }

        $this->status = DataImportStatus::ReadFailed;
        $this->readError = $reason;
        $this->sheetsTotal = null;
        $this->sheetsRead = 0;
        $this->rowsRead = 0;
        $this->lastActivityAt = $now;
    }

    /**
     * "Try again": a read that failed waits for the worker once more.
     */
    public function readAgain(DateTimeImmutable $now): bool
    {
        if ($this->status !== DataImportStatus::ReadFailed || $this->fileKey === null) {
            return false;
        }

        $this->status = DataImportStatus::Uploaded;
        $this->readError = null;
        $this->readingStartedAt = null;
        $this->lastActivityAt = $now;

        return true;
    }

    /**
     * The player already has an import of the very same bytes: this one is closed, that one is where they go on.
     */
    public function closedAsSameFileAs(self $other, DateTimeImmutable $now): void
    {
        $this->sameFileAsImportId = $other->id;
        $this->discard($now);
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
