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
use SpeedPuzzling\Web\Value\DataImportUndoScope;

/**
 * One Undo of what an import added (docs/features/data-import/adding.md "Undo"): chosen rows, one "Add", or the whole
 * import - asked by the player, or by an admin with a reason. The rows it takes are marked with its id
 * (`data_import_row.undo_id`) until each is done; this row counts what happened and stays for good - who took back
 * what, when and why.
 */
#[Entity]
#[Index(columns: ['import_id', 'started_at'])]
#[Index(columns: ['state', 'last_activity_at'])]
class DataImportUndo
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 16, enumType: DataImportBatchState::class)]
    public DataImportBatchState $state = DataImportBatchState::Running;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $lastActivityAt;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $finishedAt = null;

    /** The number of the turn that may run - DataImportBatch::$turn */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 1])]
    public int $turn = 1;

    /** Rows that are back to waiting */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $rowsUndone = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $resultsDeleted = 0;

    /**
     * Rows kept just for the player (their puzzle is not in the database) whose result or mark this Undo removed -
     * nothing of them was on the profile, so no other number of the Undo counts them
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $keptRowsRemoved = 0;

    /** Results somebody changed after the import (time, day, puzzle, people) - kept, their rows stay added */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $resultsKeptEdited = 0;

    /** Of the deleted ones: results that had been changed since - only with `include_edited` */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $resultsDeletedEdited = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $collectionItemsRemoved = 0;

    /** Collection items moved to another collection since - kept */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $collectionItemsKept = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $wishListItemsRemoved = 0;

    /** Wish list items the import had removed ("remove when solved / added to my collection") and that are back */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $wishListItemsRestored = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $rowsFailed = 0;

    /**
     * The registered partners whose results this Undo deleted: each is owed one recalculation of their achievements
     * when the Undo is through - once per partner, however many results (docs/features/data-import/people.md).
     *
     * @var list<string>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $partnerPlayerIds = [];

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
        #[Immutable]
        #[Column(length: 8, enumType: DataImportUndoScope::class)]
        public DataImportUndoScope $scope,
        /** The "Add" it takes back - scope `batch` */
        #[Immutable]
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $batchId,
        /** Rows it took */
        #[Immutable]
        #[Column]
        public int $rowsRequested,
        /** Who asked: the player, or an admin - a plain id, it outlives the account */
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $requestedByPlayerId,
        #[Immutable]
        #[Column]
        public bool $byAdmin,
        /** Results changed since the import are deleted too - only ever for chosen rows, by the player's own word */
        #[Immutable]
        #[Column]
        public bool $includeEdited,
        #[Immutable]
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $reason,
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

    public function isTurn(int $turn): bool
    {
        return $this->turn === $turn;
    }

    public function nextTurn(DateTimeImmutable $now): int
    {
        $this->lastActivityAt = $now;

        return ++$this->turn;
    }

    public function workedOn(DateTimeImmutable $now): void
    {
        $this->lastActivityAt = $now;
    }

    /**
     * One row of it is done.
     *
     * @param array{undone: bool, resultDeleted: bool, resultKeptEdited: bool, resultWasEdited: bool, collectionItemRemoved: bool, collectionItemKept: bool, wishListItemRemoved: bool, wishListItemsRestored: int, keptRowRemoved?: bool} $outcome
     */
    public function rowDone(array $outcome, DateTimeImmutable $now): void
    {
        $this->lastActivityAt = $now;
        $this->rowsUndone += $outcome['undone'] ? 1 : 0;
        $this->resultsDeleted += $outcome['resultDeleted'] ? 1 : 0;
        $this->keptRowsRemoved += ($outcome['keptRowRemoved'] ?? false) ? 1 : 0;
        $this->resultsKeptEdited += $outcome['resultKeptEdited'] ? 1 : 0;
        $this->resultsDeletedEdited += $outcome['resultDeleted'] && $outcome['resultWasEdited'] ? 1 : 0;
        $this->collectionItemsRemoved += $outcome['collectionItemRemoved'] ? 1 : 0;
        $this->collectionItemsKept += $outcome['collectionItemKept'] ? 1 : 0;
        $this->wishListItemsRemoved += $outcome['wishListItemRemoved'] ? 1 : 0;
        $this->wishListItemsRestored += $outcome['wishListItemsRestored'];
    }

    /**
     * @param list<string> $playerIds
     */
    public function partnersOwed(array $playerIds): void
    {
        $this->partnerPlayerIds = array_values(array_unique([...$this->partnerPlayerIds, ...$playerIds]));
    }

    public function rowFailed(DateTimeImmutable $now): void
    {
        $this->lastActivityAt = $now;
        $this->rowsFailed++;
    }

    public function finished(DateTimeImmutable $now): void
    {
        $this->state = DataImportBatchState::Finished;
        $this->finishedAt = $now;
        $this->lastActivityAt = $now;
    }
}
