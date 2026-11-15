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
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetProposal;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportRowMeaning;
use SpeedPuzzling\Web\Value\DataImportSheetKind;

/**
 * A sheet (tab) of an import (docs/features/data-import/README.md "Data model"), written when the file is read
 * (docs/features/data-import/reading.md): its place, name, whether it is hidden, how many rows and columns hold
 * values. Then the import proposes what it holds (docs/features/data-import/columns.md): its kind, its table, its
 * mapping and options - each change of them is a new `mappingVersion`, and its rows are parsed for one version.
 */
#[Entity]
#[Index(columns: ['import_id', 'position'])]
class DataImportSheet
{
    /** The proposed mapping could not be stored */
    public const string SET_UP_MAPPING_INVALID = 'mapping_invalid';
    /** The proposal was started DataImportLimits::MAX_SET_UP_ATTEMPTS times and never arrived */
    public const string SET_UP_PROPOSAL_FAILED = 'proposal_failed';
    /** The parse of the current mapping was started that often and never ended */
    public const string SET_UP_PARSE_FAILED = 'parse_failed';

    public const string PARSE_CLAIMED = 'claimed';
    public const string PARSE_NOT_NEEDED = 'not_needed';
    public const string PARSE_GIVEN_UP = 'given_up';

    #[Column(length: 100, nullable: true)]
    public null|string $googleGid = null;

    #[Column]
    public bool $included = true;

    #[Column(length: 16, nullable: true, enumType: DataImportSheetKind::class)]
    public null|DataImportSheetKind $kind = null;

    /** The table or range that was read */
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $region = null;

    #[Column(nullable: true)]
    public null|int $headerRow = null;

    #[Column(length: 80, nullable: true)]
    public null|string $headerSignature = null;

    #[Column(length: 16, nullable: true, enumType: DataImportRowMeaning::class)]
    public null|DataImportRowMeaning $rowMeaning = null;

    /** @var null|array<mixed> */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $mapping = null;

    /** @var null|array<mixed> date order, year, sheet-wide values, the lookup sheet */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $options = null;

    /** @var null|array<mixed> */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $valueAnswers = null;

    /** A tab the spreadsheet does not show - listed, and marked */
    #[Column(options: ['default' => false])]
    public bool $hidden = false;

    /** Rows with at least one value */
    #[Column]
    public int $rowsTotal = 0;

    /** The right-most column a value stands in */
    #[Column(options: ['default' => 0])]
    public int $columnsTotal = 0;

    /**
     * What the file says about the sheet, kept for the step that finds its header and its tables - the file is not
     * opened again (Services\DataImport\ImportSheetHints): Excel tables, the frozen pane, the used range.
     *
     * @var null|array<mixed>
     */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $hints = null;

    #[Column]
    public int $rowsLeftOut = 0;

    /**
     * Which mapping the sheet has: 0 = none yet, 1 = the import's proposal, + 1 with every change the player confirms
     * (docs/features/data-import/columns.md "Versions"). Rows are parsed for one version; a job of an older one writes
     * nothing.
     */
    #[Column(options: ['default' => 0])]
    public int $mappingVersion = 0;

    /** The mapping version the sheet's rows were last parsed for, to the end - null: never */
    #[Column(nullable: true)]
    public null|int $parsedVersion = null;

    /** The mapping version a parse was last started for, and when it last gave news - the claim of ParseImportSheet */
    #[Column(nullable: true)]
    public null|int $parsingVersion = null;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $parsingStartedAt = null;

    /** How often the step the sheet waits for (its proposal, the parse of its current mapping) was started */
    #[Column(options: ['default' => 0])]
    public int $setUpAttempts = 0;

    /** Why the sheet's set-up ended without its rows: SET_UP_* - null while nothing failed */
    #[Column(length: 32, nullable: true)]
    public null|string $setUpFailure = null;

    /** Rows that are puzzles and hold nothing unclear */
    #[Column(options: ['default' => 0])]
    public int $rowsUnderstood = 0;

    /** Rows that are puzzles and wait with a problem */
    #[Column(options: ['default' => 0])]
    public int $rowsNeedLook = 0;

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
        #[Column]
        public int $position,
        #[Immutable]
        #[Column(type: Types::TEXT)]
        public string $title,
    ) {
    }

    /**
     * The import's own proposal - only for a sheet that has no mapping yet.
     */
    public function mappingProposed(ImportSheetProposal $proposal): bool
    {
        if ($this->mappingVersion !== 0) {
            return false;
        }

        $this->mappingVersion = 1;
        $this->headerSignature = $proposal->headerSignature;
        $this->mapped($proposal->mapping, $proposal->options, $proposal->valueAnswers);

        return true;
    }

    /**
     * The player's mapping or answers: a new version - what was parsed for the old one is out of date.
     *
     * @param array<array-key, mixed> $valueAnswers
     */
    public function mappingConfirmed(ImportMapping $mapping, ImportSheetOptions $options, array $valueAnswers): void
    {
        $this->mappingVersion++;
        $this->mapped($mapping, $options, $valueAnswers);
    }

    /**
     * The sheet this one looks brand and pieces up in was mapped anew: its rows must be read again.
     */
    public function lookedUpSheetChanged(): void
    {
        $this->mappingVersion++;
        $this->startsOver();
    }

    /**
     * A parse of the current mapping wants to start (ParseImportSheet): it may once. Not when the sheet is parsed
     * for this version, when its set-up failed, or while another job's claim is fresh (it gave news after
     * $deadBefore). Started DataImportLimits::MAX_SET_UP_ATTEMPTS times without ever ending, the sheet is given up.
     *
     * @return self::PARSE_*
     */
    public function claimParse(int $mappingVersion, DateTimeImmutable $now, DateTimeImmutable $deadBefore): string
    {
        if ($mappingVersion !== $this->mappingVersion || $this->isParsed() || $this->setUpFailure !== null) {
            return self::PARSE_NOT_NEEDED;
        }

        if ($this->parsingVersion === $mappingVersion && $this->parsingStartedAt !== null && $this->parsingStartedAt > $deadBefore) {
            return self::PARSE_NOT_NEEDED;
        }

        if ($this->setUpAttempts >= DataImportLimits::MAX_SET_UP_ATTEMPTS) {
            $this->setUpFailure = self::SET_UP_PARSE_FAILED;

            return self::PARSE_GIVEN_UP;
        }

        $this->setUpAttempts++;
        $this->parsingVersion = $mappingVersion;
        $this->parsingStartedAt = $now;

        return self::PARSE_CLAIMED;
    }

    /**
     * The running parse wrote a batch: its claim stays fresh.
     */
    public function parseGoesOn(DateTimeImmutable $now): void
    {
        $this->parsingStartedAt = $now;
    }

    /**
     * The proposal of the sheet's mapping is asked for again (the safety net) - false: asked too often, given up.
     */
    public function proposalRequestedAgain(): bool
    {
        if ($this->setUpAttempts >= DataImportLimits::MAX_SET_UP_ATTEMPTS) {
            $this->setUpFailure = self::SET_UP_PROPOSAL_FAILED;

            return false;
        }

        $this->setUpAttempts++;

        return true;
    }

    /**
     * The sheet's set-up ends here, visibly - nothing queues it again until the player changes its mapping.
     */
    public function setUpFailed(string $failure): void
    {
        $this->setUpFailure = $failure;
    }

    /**
     * Whether a step that runs by itself may still be queued for the sheet.
     */
    public function waitsForItsParse(): bool
    {
        return $this->mappingVersion > 0 && $this->isParsed() === false && $this->setUpFailure === null;
    }

    public function isParsed(): bool
    {
        return $this->parsedVersion === $this->mappingVersion;
    }

    public function parsed(int $mappingVersion, int $understood, int $needLook, int $leftOut): void
    {
        $this->parsedVersion = $mappingVersion;
        $this->parsingVersion = null;
        $this->parsingStartedAt = null;
        $this->rowsUnderstood = $understood;
        $this->rowsNeedLook = $needLook;
        $this->rowsLeftOut = $leftOut;
    }

    /**
     * @param array<array-key, mixed> $valueAnswers
     */
    private function mapped(ImportMapping $mapping, ImportSheetOptions $options, array $valueAnswers): void
    {
        $table = $mapping->table();
        $this->mapping = $mapping->toArray();
        $this->options = $options->toArray();
        $this->valueAnswers = $valueAnswers === [] ? null : $valueAnswers;
        $this->kind = $mapping->kind;
        $this->rowMeaning = $mapping->rowMeaning;
        $this->region = $table?->range();
        $this->headerRow = $table?->headerRow;
        $this->startsOver();
    }

    /**
     * A new mapping version: nothing of the last one's attempts, claim or failure counts for it.
     */
    private function startsOver(): void
    {
        $this->setUpAttempts = 0;
        $this->setUpFailure = null;
        $this->parsingVersion = null;
        $this->parsingStartedAt = null;
    }

    /**
     * A batch of the sheet's rows was stored.
     */
    public function rowsStored(int $rows, int $lastColumn): void
    {
        $this->rowsTotal += $rows;
        $this->columnsTotal = max($this->columnsTotal, $lastColumn);
    }
}
