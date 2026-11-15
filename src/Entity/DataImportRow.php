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
use Doctrine\ORM\Mapping\UniqueConstraint;
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
 * `importPuzzleId` names the row's distinct puzzle (`data_import_puzzle`, written set-based by the pairing -
 * DataImportPuzzleRepository::linkRows()); `batchId` points at a table of a later stage (`data_change_batch`). Plain ids.
 */
#[Entity]
#[Index(columns: ['sheet_id', 'row_number'])]
#[Index(columns: ['import_id', 'state'])]
#[Index(columns: ['import_id', 'source_key'])]
#[Index(columns: ['import_puzzle_id'])]
// The database's last word on "a result is one row's": two rows can never name the same result (NULLs are distinct)
#[UniqueConstraint(columns: ['solving_time_id'])]
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

    /** `<version>:<sha256>` of name + pieces + brand as the row says them (ImportPuzzleKey) - null without a name */
    #[Column(length: 80, nullable: true)]
    public null|string $puzzleKey = null;

    /**
     * What rows of one sheet are equal by: the row's key before equal rows are numbered (a result's key with
     * occurrence 1). It never changes with the numbering, so numbering a sheet again finds the same groups.
     */
    #[Column(length: 80, nullable: true)]
    public null|string $equalKey = null;

    /** The sheet's mapping version the row was last parsed for */
    #[Column(nullable: true)]
    public null|int $parsedVersion = null;

    /**
     * The row this one is the same result as - one result unless the player says two (README D6): an equal row of
     * its own sheet, or the same row on an earlier sheet. At most two steps lead to the row that stands for them all.
     */
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

    /**
     * The result this row made (docs/features/data-import/writer.md "The link") - a plain id on purpose: it stays
     * when the result is deleted, and whether the row's result exists is always asked of the results themselves.
     * Written in the database by DataImportRowRepository::linkSolvingTime() (compare-and-set); resultCreated() keeps
     * the loaded row in step
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $solvingTimeId = null;

    /**
     * A hint of the pairing, worked out again by every run while the row waits (docs/features/data-import/pairing.md
     * "Already yours"): `profile` = a result on the player's profile has the row's day, time and kind on a puzzle of
     * the same piece count; `earlier_import` = a row with the same key in an import of the player made a result that
     * still exists. The row's `state` is not touched - it is settled when the row is added ("Never twice").
     */
    #[Column(length: 16, nullable: true)]
    public null|string $alreadyYoursHow = null;

    /** The result the hint is about - a plain id, the result may go */
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $alreadyYoursSolvingTimeId = null;

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

    /**
     * ImportedResultWriter made a result of the row. A row whose earlier result is gone (deleted, undone) may make
     * another - the link then names the new one.
     */
    public function resultCreated(UuidInterface $solvingTimeId): void
    {
        $this->solvingTimeId = $solvingTimeId;
    }

    /**
     * Whether parsing may write the row: only while nothing was made of it. A row that is queued, added, already the
     * player's, failed or undone keeps what it was parsed as.
     */
    public function canBeParsed(): bool
    {
        return $this->state === DataImportRowState::Waiting || $this->state === DataImportRowState::LeftOut;
    }

    /**
     * What ImportRowParser read of the row under one version of its sheet's mapping (ParsedImportRow::toRecord()).
     * A row that is no data is left out; any other waits for its puzzle.
     *
     * @param array{kind: string, parsed: array<string, mixed>, problems: list<array<string, mixed>>, puzzleKey: null|string, sourceKey: null|string, sourceRowId: null|string, leftOut: bool} $record
     */
    public function parsedAs(int $mappingVersion, array $record): void
    {
        $this->parsedVersion = $mappingVersion;
        $this->kind = DataImportRowKind::from($record['kind']);
        $this->parsed = $record['parsed'];
        $this->problems = $record['problems'] === [] ? null : $record['problems'];
        $this->puzzleKey = $record['puzzleKey'];
        $this->sourceKey = $record['sourceKey'];
        $this->equalKey = $record['sourceKey'];
        $this->sourceRowId = $record['sourceRowId'];
        $this->sameAsRow = null;
        $this->state = $record['leftOut'] ? DataImportRowState::LeftOut : DataImportRowState::Waiting;
    }

    /**
     * What the player said about this very row (Services\DataImport\ImportRowAnswers): values typed for its fields,
     * "leave it out". The row is parsed again right after (AnswerImportRowsHandler) - and every later parse reads
     * the answers with the cells.
     *
     * @param array<string, mixed> $answers
     */
    public function answered(array $answers): void
    {
        $this->answers = $answers === [] ? null : $answers;
    }

    /**
     * What a page that shows the row sends back with a change: another stamp in the database means the row was
     * parsed for another mapping or answered meanwhile.
     */
    public function stamp(): string
    {
        return self::stampOf($this->parsedVersion, $this->answers);
    }

    /**
     * @param null|array<mixed> $answers
     */
    public static function stampOf(null|int $parsedVersion, null|array $answers): string
    {
        $answers ??= [];
        ksort($answers);

        foreach ($answers as $field => $answer) {
            if (is_array($answer)) {
                ksort($answer);
                $answers[$field] = $answer;
            }
        }

        return md5(($parsedVersion ?? 0) . ':' . json_encode($answers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * One of several rows of its sheet that are equal in all a key holds: which one it is, and whether it stands for
     * itself (ImportEqualRows).
     */
    public function numberedAmongEqualRows(int $occurrence, null|string $sourceKey, null|self $sameAs): void
    {
        $parsed = $this->parsed ?? [];
        $parsed['occurrence'] = $occurrence;

        if ($sourceKey !== null && $this->equalKey !== null) {
            $keys = is_array($parsed['keys'] ?? null) ? $parsed['keys'] : [];
            $keys['result'] = $sourceKey;
            $parsed['keys'] = $keys;
            $this->sourceKey = $sourceKey;
        }

        $this->parsed = $parsed;
        $this->sameAsRow = $sameAs;
    }
}
