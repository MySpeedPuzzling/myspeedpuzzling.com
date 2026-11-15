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
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportRowState;

/**
 * A row of a sheet of an import (docs/features/data-import/README.md "Data model"). Its key, state and outcomes are
 * kept for ever; `cells` and `parsed` can be wiped. Written when the file is read: `cells` in the shape of
 * Services\DataImport\ImportRowCells (docs/features/data-import/reading.md "What a row keeps").
 *
 * `importPuzzleId` and `batchId` point at tables of later stages (`data_import_puzzle`, `data_change_batch`): plain
 * ids until those exist.
 */
#[Entity]
#[Index(columns: ['sheet_id', 'row_number'])]
#[Index(columns: ['import_id', 'state'])]
class DataImportRow
{
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $importPuzzleId = null;

    /** The source's own id of the row, when the file has one - then it is the row's identity */
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $sourceRowId = null;

    /** @var null|array<mixed> typed value + shown text per column */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $cells = null;

    /** @var null|array<mixed> */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $parsed = null;

    /** @var null|array<mixed> [{field, code, choices}] */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $problems = null;

    /** @var null|array<mixed> */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $answers = null;

    #[Column(length: 16, nullable: true, enumType: DataImportRowKind::class)]
    public null|DataImportRowKind $kind = null;

    /** `<version>:<sha256>` (docs/features/data-import/value-readers.md "Keys") */
    #[Column(length: 80, nullable: true)]
    public null|string $sourceKey = null;

    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public null|DataImportRow $sameAsRow = null;

    #[Column]
    public bool $addAsSeparate = false;

    #[Column(length: 16, enumType: DataImportRowState::class)]
    public DataImportRowState $state = DataImportRowState::Waiting;

    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $failure = null;

    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $batchId = null;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $addedAt = null;

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
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public DataImportSheet $sheet,
        #[Immutable]
        #[Column]
        public int $rowNumber,
        #[Immutable]
        #[Column]
        public int $firstSeenVersion,
        #[Column]
        public int $lastSeenVersion,
    ) {
    }
}
