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
use SpeedPuzzling\Web\Value\DataImportLimits;
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

    /**
     * The document's id of an import that came as a Google Sheets link - the id only, never the pasted link: what
     * "Try again" (and, later, "Look for new rows") fetches again
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 100, nullable: true)]
    public null|string $googleSpreadsheetId = null;

    /** The worker took the import for fetching from Google - a second copy of the message finds nothing to do */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $fetchStartedAt = null;

    /**
     * Raised every time a fetch starts: what a fetch writes carries its number, so a fetch that was given up can not
     * write into the next one.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $fetchAttempt = 0;

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

    /**
     * The import is finished (docs/features/data-import/upload.md "Finished"): by the player's "I'm done with this
     * import" or by the daily sweep once nothing of it is left to add. What it added stays and can be taken back
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $finishedAt = null;

    /**
     * When reading was last asked for (ReadImportFile queued): the safety net asks again only when this is old - never
     * a second message for the same waiting import on every run.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $readRequestedAt = null;

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

    /**
     * Raised every time the import's puzzles are built again (BuildImportPuzzles): the similar-name search carries its
     * number, so a search of an older run stops (docs/features/data-import/pairing.md "The flow").
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $pairingRun = 0;

    /**
     * When the pairing last came to rest: every puzzle has its tier. Null while it runs, or before the first run.
     * The import stays `setting_up` - a mapping can still change, and then the pairing runs again.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $pairedAt = null;

    /**
     * The pairing's own sign of life: when a run was last asked for, started or wrote a chunk of its search. What a
     * page's "the pairing runs" and the safety net's "the run is standing" read - never `lastActivityAt`, which
     * every decision and answer of the player moves too (a failed run must not look alive because the player works).
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $pairingAliveAt = null;

    /**
     * How often the safety net asked for the pairing again because a run did not come to rest (it failed - a puzzle
     * merge beside it, a fault of ours; the queue has no retries). Bounded like a sheet's set-up attempts; back to
     * zero when a run ends.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $pairingRequeues = 0;

    /**
     * "Where the rules end" - numbers only (ImportPairingCounters): puzzles per tier as the rules left them, and how
     * often the player decided otherwise.
     *
     * @var null|array<mixed>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $pairingCounters = null;

    /**
     * The player's answers that stand for the whole file (docs/features/data-import/adding.md "A question is asked
     * once"): `{"unusual_time": "2026-11-15T10:00:00+00:00", …}` - the question's code (STANDING_QUESTIONS only)
     * and when the answer "add them" was given for all its rows. A row of this import that reaches the question in
     * a later "Add" is not sent back with it (ImportResultQuestions writes the row's own waiver, with the row's
     * fingerprint, when it adds the row). Never for a possible duplicate - those are answered one by one.
     *
     * @var null|array<string, string>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $standingAnswers = null;

    /** The only questions an answer can stand for: the two one answer is right for (group-answers.md) */
    public const array STANDING_QUESTIONS = ['unusual_time', 'said_already_on_profile'];

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
        /** '' for a Google sheet whose title is not known (yet) - the pages say "Google sheet" then */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::TEXT)]
        public string $fileName,
        #[Immutable]
        #[Column(length: 8, nullable: true, enumType: DataImportFileKind::class)]
        public null|DataImportFileKind $fileKind,
        /**
         * The size the upload was signed for - the object must have exactly this many bytes. Of a Google sheet: what
         * arrived, known once it was fetched
         */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
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

    /**
     * A Google sheet the worker is about to fetch (docs/features/data-import/google-sheets.md) - nothing is stored
     * yet. The key is known from the start, so a discard (and the prune after it) removes whatever a fetch stored
     * behind it.
     */
    public static function ofGoogleSheet(
        UuidInterface $id,
        Player $player,
        string $spreadsheetId,
        string $fileKey,
        DateTimeImmutable $now,
    ): self {
        $import = new self($id, $player, DataImportSource::GoogleSheet, '', DataImportFileKind::Xlsx, null, $fileKey, $now);
        $import->status = DataImportStatus::Fetching;
        $import->googleSpreadsheetId = $spreadsheetId;

        return $import;
    }

    /**
     * The worker takes the Google sheet. Only an import that waits for it.
     *
     * @return bool false: nothing to fetch (being fetched already, fetched, failed, discarded)
     */
    public function fetchStarted(DateTimeImmutable $now): bool
    {
        if ($this->status !== DataImportStatus::Fetching || $this->fetchStartedAt !== null || $this->googleSpreadsheetId === null || $this->fileKey === null) {
            return false;
        }

        $this->fetchAttempt++;
        $this->fetchStartedAt = $now;
        $this->lastActivityAt = $now;

        return true;
    }

    /**
     * Whether what the fetch of this number brought may still be recorded.
     */
    public function isBeingFetchedBy(int $fetchAttempt): bool
    {
        return $this->status === DataImportStatus::Fetching && $this->fetchStartedAt !== null && $this->fetchAttempt === $fetchAttempt;
    }

    /**
     * The export is in object storage under the import's key: from here on it is an uploaded file like any other.
     * Reading is asked for by RequestImportRead right after - `readRequestedAt` stays empty until then, so an import
     * whose request got lost is found by the safety net like one nobody ever asked for.
     *
     * @param null|string $fileName the document's title as Google named the download - null when it gave none
     */
    public function fetched(null|string $fileName, int $fileSize, DateTimeImmutable $now): void
    {
        if ($this->status !== DataImportStatus::Fetching) {
            return;
        }

        $this->status = DataImportStatus::Uploaded;
        $this->fileName = $fileName ?? $this->fileName;
        $this->fileSize = $fileSize;
        $this->readError = null;
        $this->uploadedAt = $now;
        $this->readRequestedAt = null;
        $this->lastActivityAt = $now;
    }

    /**
     * @param string $reason a GoogleSheetFetchFailure's value, or DataImportReadFailure::OUR_SIDE / ::STALLED
     */
    public function fetchFailed(string $reason, DateTimeImmutable $now): void
    {
        if ($this->status !== DataImportStatus::Fetching) {
            return;
        }

        $this->status = DataImportStatus::FetchFailed;
        $this->readError = $reason;
        $this->lastActivityAt = $now;
    }

    /**
     * "Try again": a sheet Google did not hand over waits for the worker once more.
     */
    public function fetchAgain(DateTimeImmutable $now): bool
    {
        if ($this->status !== DataImportStatus::FetchFailed || $this->googleSpreadsheetId === null || $this->fileKey === null) {
            return false;
        }

        $this->status = DataImportStatus::Fetching;
        $this->readError = null;
        $this->fetchStartedAt = null;
        $this->lastActivityAt = $now;

        return true;
    }

    public function isOwnedBy(string $playerId): bool
    {
        return $this->player->id->toString() === $playerId;
    }

    /**
     * "Add them" was said for every row of a question: it stands for the rows of this import that reach the
     * question later. Only for the two questions one answer is right for.
     */
    public function answerStandsFor(string $code, DateTimeImmutable $now): void
    {
        if (in_array($code, self::STANDING_QUESTIONS, true) === false || isset($this->standingAnswers[$code])) {
            return;
        }

        $this->standingAnswers = [...($this->standingAnswers ?? []), $code => $now->format(DATE_ATOM)];
    }

    /**
     * "Change": the question is asked again from now on. What rows were answered before stays answered.
     */
    public function standingAnswerTakenBack(string $code): bool
    {
        if (isset($this->standingAnswers[$code]) === false) {
            return false;
        }

        $answers = $this->standingAnswers;
        unset($answers[$code]);
        $this->standingAnswers = $answers === [] ? null : $answers;

        return true;
    }

    public function hasStandingAnswerFor(string $code): bool
    {
        return in_array($code, self::STANDING_QUESTIONS, true) && is_string($this->standingAnswers[$code] ?? null);
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
        $this->readRequestedAt = $now;
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

    /**
     * Read, and its sheets are being recognised, mapped and parsed (docs/features/data-import/columns.md) - the only
     * state in which a mapping is proposed, confirmed or parsed.
     */
    public function isBeingSetUp(): bool
    {
        return $this->status === DataImportStatus::SettingUp;
    }

    /**
     * A background step of the setting up wrote something.
     */
    public function workedOn(DateTimeImmutable $now): void
    {
        $this->lastActivityAt = $now;
    }

    /**
     * The import's puzzles are built and paired again.
     *
     * @return int the run's number
     */
    public function pairingStarted(DateTimeImmutable $now): int
    {
        $this->pairingRun++;
        $this->pairedAt = null;
        $this->pairingAliveAt = $now;
        $this->lastActivityAt = $now;

        return $this->pairingRun;
    }

    /**
     * Rows of the import were read again (a sheet parsed, rows answered): the pairing is due once more - its run is
     * queued. Until that run comes to rest the import says "not paired", so its pages keep following it and the
     * safety net asks for the run again when its message is lost.
     */
    public function pairingDue(DateTimeImmutable $now): void
    {
        $this->pairedAt = null;
        $this->pairingAliveAt = $now;
        $this->lastActivityAt = $now;
    }

    /**
     * A chunk of the run's similar-name search was written: the run is alive.
     */
    public function pairingGoesOn(DateTimeImmutable $now): void
    {
        $this->pairingAliveAt = $now;
        $this->lastActivityAt = $now;
    }

    public function isPairingRun(int $run): bool
    {
        return $this->pairingRun === $run && $this->isBeingSetUp();
    }

    /**
     * @param array<string, mixed> $counters ImportPairingCounters::toArray()
     */
    public function pairingCounted(array $counters): void
    {
        $this->pairingCounters = $counters;
    }

    /**
     * A decision of the player moved counters (DecideImportPuzzlesHandler).
     *
     * @param array<string, int> $moved by counter - `remembered` or `player.<name>` - how much up or down
     */
    public function pairingCountersMoved(array $moved): void
    {
        $counters = $this->pairingCounters ?? [];

        foreach ($moved as $counter => $by) {
            if ($by === 0) {
                continue;
            }

            if ($counter === 'remembered') {
                $counters['remembered'] = max(0, (is_int($counters['remembered'] ?? null) ? $counters['remembered'] : 0) + $by);

                continue;
            }

            $name = substr($counter, strlen('player.'));
            $player = is_array($counters['player'] ?? null) ? $counters['player'] : [];
            $player[$name] = max(0, (is_int($player[$name] ?? null) ? $player[$name] : 0) + $by);
            $counters['player'] = $player;
        }

        $this->pairingCounters = $counters;
    }

    /**
     * Every puzzle of the run has its tier.
     *
     * @param array<string, mixed> $counters ImportPairingCounters::toArray()
     */
    public function paired(array $counters, DateTimeImmutable $now): void
    {
        $this->pairingCounters = $counters;
        $this->pairedAt = $now;
        $this->pairingAliveAt = $now;
        $this->pairingRequeues = 0;
        $this->lastActivityAt = $now;
    }

    /**
     * The safety net asks for the pairing once more (RequestImportPairing).
     *
     * @return bool false: it is not waited for, or it was asked for often enough
     */
    public function pairingRequestedAgain(DateTimeImmutable $now): bool
    {
        if ($this->isBeingSetUp() === false || $this->pairedAt !== null || $this->cellsWipedAt !== null || $this->pairingRequeues >= DataImportLimits::MAX_SET_UP_ATTEMPTS) {
            return false;
        }

        $this->pairingRequeues++;
        $this->pairingAliveAt = $now;
        $this->lastActivityAt = $now;

        return true;
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
        $this->readRequestedAt = $now;
        $this->lastActivityAt = $now;

        return true;
    }

    /**
     * The safety net asks for the read of an import that still waits for it.
     *
     * @return bool false: it does not wait (any more), or it was asked for after `$notAskedSince`
     */
    public function readRequestedAgain(DateTimeImmutable $now, DateTimeImmutable $notAskedSince): bool
    {
        if ($this->status !== DataImportStatus::Uploaded || $this->fileKey === null) {
            return false;
        }

        if ($this->readRequestedAt !== null && $this->readRequestedAt >= $notAskedSince) {
            return false;
        }

        $this->readRequestedAt = $now;

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

    public function isFinished(): bool
    {
        return $this->status === DataImportStatus::Done;
    }

    /**
     * Nothing more is added from the import: it no longer holds one of the player's places
     * (DataImportLimits::MAX_UNFINISHED_PER_PLAYER). Its rows, what it added and Undo stay as they are. Only an
     * import that is read and set up - an earlier one is discarded or tried again instead.
     *
     * @return bool false: it is not at a stage that can be finished (or finished already)
     */
    public function finish(DateTimeImmutable $now): bool
    {
        if (in_array($this->status, [DataImportStatus::SettingUp, DataImportStatus::Reviewing], true) === false) {
            return false;
        }

        $this->status = DataImportStatus::Done;
        $this->finishedAt = $now;
        $this->lastActivityAt = $now;

        return true;
    }

    /**
     * Whether "Go on with this import" can open it again: its rows still hold what the spreadsheet said.
     */
    public function canGoOn(): bool
    {
        return $this->status === DataImportStatus::Done && $this->cellsWipedAt === null;
    }

    /**
     * "Go on with this import": a finished import is set up again where it stood.
     */
    public function goOn(DateTimeImmutable $now): bool
    {
        if ($this->canGoOn() === false) {
            return false;
        }

        $this->status = DataImportStatus::SettingUp;
        $this->finishedAt = null;
        $this->lastActivityAt = $now;

        return true;
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
