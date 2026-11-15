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
 * One answer of the player for a whole group of rows of their import (docs/features/data-import/group-answers.md):
 * "these times are right - add all 416", "they are missing - add all 441", "leave them out" - for every row that
 * waits with one reason, or (before any "Add") for every ready result whose time the verification finds unusual.
 *
 * The rows are worked out by the server and gone through in bounded chunks - on the `imports` queue when there are
 * many - so the answer keeps how far it is (`lastRowId`, `rowsDone`): its page follows it, and a turn delivered twice
 * or started again repeats nothing. Its id is the id the form chose for its press: the same form sent again answers
 * this very row. Kept for good, like a batch: who answered what for how many rows.
 */
#[Entity]
#[Index(columns: ['import_id', 'started_at'])]
class DataImportGroupAnswer
{
    public const string ADD_ANYWAY = 'add_anyway';
    public const string LEAVE_OUT = 'leave_out';

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 16, enumType: DataImportBatchState::class)]
    public DataImportBatchState $state = DataImportBatchState::Running;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $lastActivityAt;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $finishedAt = null;

    /** The last row a turn came to - the next turn goes on after it */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $lastRowId = null;

    /** Rows looked at so far */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $rowsDone = 0;

    /** Rows that took the answer */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $rowsAnswered = 0;

    /**
     * The rows that took "add it anyway" - what the "Add" at the end names. Ids only.
     *
     * @var list<string>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $answeredRowIds = [];

    /** The "Add" it started at its end - null when none was started */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $batchId = null;

    /** Why no "Add" was started (an AddImportRowsOutcome status: paused, already_running, nothing …) */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 32, nullable: true)]
    public null|string $addStatus = null;

    public function __construct(
        /** The id the form chose for its press */
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(name: 'import_id', nullable: false, onDelete: 'CASCADE')]
        public DataImport $import,
        /** The reason the rows wait with (ImportRowRefusal's value) */
        #[Immutable]
        #[Column(length: 32)]
        public string $code,
        /** ADD_ANYWAY or LEAVE_OUT */
        #[Immutable]
        #[Column(length: 16)]
        public string $action,
        /** Only the rows one "Add" could not add - null: of the whole import */
        #[Immutable]
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $ofBatchId,
        /**
         * Before any "Add" (the review): the rows are the ready results with a time, and each is shown to the time
         * verification first - only an unusual one takes the answer. Nothing is added at the end.
         */
        #[Immutable]
        #[Column]
        public bool $beforeAdd,
        /** Rows the answer set out for */
        #[Immutable]
        #[Column]
        public int $rowsTotal,
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

    /**
     * A turn went through rows.
     *
     * @param list<string> $answeredRowIds the rows that took the answer
     */
    public function turnDone(int $rows, array $answeredRowIds, null|UuidInterface $cursor, DateTimeImmutable $now): void
    {
        $this->rowsDone += $rows;
        $this->rowsAnswered += count($answeredRowIds);
        $this->lastRowId = $cursor ?? $this->lastRowId;
        $this->lastActivityAt = $now;

        if ($this->action === self::ADD_ANYWAY && $this->beforeAdd === false) {
            $this->answeredRowIds = [...$this->answeredRowIds, ...$answeredRowIds];
        }
    }

    public function finished(null|UuidInterface $batchId, null|string $addStatus, DateTimeImmutable $now): void
    {
        $this->state = DataImportBatchState::Finished;
        $this->finishedAt = $now;
        $this->lastActivityAt = $now;
        $this->batchId = $batchId;
        $this->addStatus = $addStatus;
    }

    public function workedOn(DateTimeImmutable $now): void
    {
        $this->lastActivityAt = $now;
    }
}
