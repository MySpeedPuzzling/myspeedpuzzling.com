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
use SpeedPuzzling\Web\Value\DataImportBatchState;

/**
 * One "Add" of an import (docs/features/data-import/adding.md): the rows it queued (`data_import_row.batch_id`), how
 * far it is, and what came of it. Its rows carry what each of them made - the batch only counts.
 *
 * `request_id` is the id the page chose for its "Add": the same request sent again answers this batch (unique).
 * Kept for good - it is the audit trail of what an import added, also after an Undo.
 */
#[Entity]
#[Index(columns: ['import_id', 'started_at'])]
#[Index(columns: ['state', 'last_activity_at'])]
class DataImportBatch
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 16, enumType: DataImportBatchState::class)]
    public DataImportBatchState $state = DataImportBatchState::Running;

    /** When a row of it was last worked on - the sweeper continues a batch that stands still */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $lastActivityAt;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $finishedAt = null;

    /** Rows that made something: a result, a puzzle in the collection, a puzzle on the wish list */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $rowsAdded = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $rowsAlreadyYours = 0;

    /** Rows a rule refused - they wait again with the reason */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $rowsRefused = 0;

    /** Rows that threw - ours */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $rowsFailed = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $resultsAdded = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $collectionItemsAdded = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $wishListItemsAdded = 0;

    /**
     * The first pass - rows the player already has are settled before any row is added (README "Never twice", step
     * 2): the last row it looked at, and when it was through.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $settledUpTo = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $settledAt = null;

    /**
     * The number of the turn that may run (docs/features/data-import/adding.md "One chain of turns"): raised whenever
     * a turn is queued - by the turn before it, or by the sweeper. A job that carries an older number is a second
     * chain (a queue that waited, a job delivered again) and ends at once.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 1])]
    public int $turn = 1;

    /** What is owed for the results is done in turns too: the last row whose result was followed up, and when all were */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $followedUpTo = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $followedUpAt = null;

    /** A solo result of the batch waits for its reconstructed prediction - one backfill job when the batch ends */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => false])]
    public bool $predictionsOwed = false;

    /** An Undo stopped the batch: its queued rows wait again, and its end tells the player nothing ("N added") */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => false])]
    public bool $endedByUndo = false;

    /** When the player was told (the notification; the e-mail is sent from it) - once */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $notifiedAt = null;

    /** The last Undo of the whole batch - who and why are on the undo (data_import_undo) */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $undoneAt = null;

    /**
     * @param array<string, int> $skipped rows of the selection that were not queued, by reason (ImportRowRefusal's
     *        values, `over_daily_limit`)
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(name: 'import_id', nullable: false, onDelete: 'CASCADE')]
        public DataImport $import,
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $requestId,
        /** Rows queued by this "Add" */
        #[Immutable]
        #[Column]
        public int $rowsRequested,
        #[Immutable]
        #[Column(type: Types::JSON, options: ['jsonb' => true])]
        public array $skipped,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $startedAt,
    ) {
        $this->lastActivityAt = $startedAt;
    }

    public function isRunning(): bool
    {
        return $this->state === DataImportBatchState::Running;
    }

    public function settledSoFar(null|UuidInterface $upTo, bool $through, DateTimeImmutable $now): void
    {
        $this->settledUpTo = $upTo ?? $this->settledUpTo;
        $this->settledAt = $through ? ($this->settledAt ?? $now) : $this->settledAt;
        $this->lastActivityAt = $now;
    }

    public function isTurn(int $turn): bool
    {
        return $this->turn === $turn;
    }

    /**
     * A turn is queued: every job with an older number is void from now on, and the sweeper sees the batch as alive.
     */
    public function nextTurn(DateTimeImmutable $now): int
    {
        $this->lastActivityAt = $now;

        return ++$this->turn;
    }

    public function followedUpSoFar(null|UuidInterface $upTo, bool $through, bool $predictionsOwed, DateTimeImmutable $now): void
    {
        $this->followedUpTo = $upTo ?? $this->followedUpTo;
        $this->followedUpAt = $through ? ($this->followedUpAt ?? $now) : $this->followedUpAt;
        $this->predictionsOwed = $this->predictionsOwed || $predictionsOwed;
        $this->lastActivityAt = $now;
    }

    public function stoppedByUndo(): void
    {
        $this->endedByUndo = true;
    }

    public function workedOn(DateTimeImmutable $now): void
    {
        $this->lastActivityAt = $now;
    }

    /**
     * Its last row is done: what the rows say now is what the batch did.
     *
     * @param array{queued: int, added: int, alreadyYours: int, refused: int, failed: int, results: int, collectionItems: int, wishListItems: int} $counts
     */
    public function finished(array $counts, DateTimeImmutable $now): void
    {
        $this->state = DataImportBatchState::Finished;
        $this->finishedAt = $now;
        $this->lastActivityAt = $now;
        $this->rowsAdded = $counts['added'];
        $this->rowsAlreadyYours = $counts['alreadyYours'];
        $this->rowsRefused = $counts['refused'];
        $this->rowsFailed = $counts['failed'];
        $this->resultsAdded = $counts['results'];
        $this->collectionItemsAdded = $counts['collectionItems'];
        $this->wishListItemsAdded = $counts['wishListItems'];
    }

    public function playerNotified(DateTimeImmutable $now): void
    {
        $this->notifiedAt = $now;
    }

    public function undone(DateTimeImmutable $now): void
    {
        $this->undoneAt = $now;
    }

    /** Rows that were not added: refused by a rule, or failed on our side */
    public function rowsNotAdded(): int
    {
        return $this->rowsRefused + $this->rowsFailed;
    }
}
