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
 * DataImportPuzzleRepository::linkRows()); `batchId` names the "Add" that last took the row (`data_import_batch`),
 * `undoId` the Undo that is taking it back right now (`data_import_undo`). Plain ids.
 *
 * **Everything the row made is on the row** (docs/features/data-import/adding.md "What a row remembers"), so Undo is
 * exact and bounded: the result (`solvingTimeId`), the collection item and the wish list item it created - never
 * one that existed before - and in `made` what it wrote of the result, the wish list items it removed and the
 * custom fields it filled.
 */
#[Entity]
#[Index(columns: ['sheet_id', 'row_number'])]
#[Index(columns: ['import_id', 'state'])]
#[Index(columns: ['import_id', 'source_key'])]
#[Index(columns: ['import_puzzle_id'])]
#[Index(columns: ['batch_id', 'state'])]
#[Index(columns: ['undo_id'])]
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

    /** The collection item this row created - never one that was there before. A plain id: the item may go */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $collectionItemId = null;

    /** The wish list item this row created - never one that was there before. A plain id: the item may go */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $wishListItemId = null;

    /**
     * What the row's last "Add" wrote, for Undo and for the audit (ImportRowAdder builds it):
     * `result` {puzzleId, seconds, day, teamId} - what an Undo compares the result with ("changed since?"),
     * `collection` {collectionId}, `removedWishListItems` [{id, puzzleId, addedAt, removeOnCollectionAdd, why}],
     * `customFields` {result: [field ids], collectionItem: [field ids], leftOut: n}, `keptByUndo` the Undo that left
     * a changed result alone. Ids and numbers only - never a cell's content.
     *
     * @var null|array<string, mixed>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $made = null;

    /**
     * Whether the row's last "Add" made its result (`solvingTimeId`). The link alone does not say so: it stays when
     * the result is undone, and a later "Add" may find the result on the profile instead of making one.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => false])]
    public bool $resultMade = false;

    /** The Undo that took the row and has not come to it yet - written set-based when the Undo is asked for */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $undoId = null;

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

    public function isQueuedIn(string $batchId): bool
    {
        return $this->state === DataImportRowState::Queued && $this->batchId?->toString() === strtolower($batchId);
    }

    /**
     * The row made something: a result (linked by the writer - resultCreated()), a collection item, a wish list item.
     * $resultAlreadyYours…: its result was the player's already (only its puzzle was added) - how, and which result.
     *
     * @param array<string, mixed> $made
     */
    public function added(
        DateTimeImmutable $now,
        null|UuidInterface $collectionItemId,
        null|UuidInterface $wishListItemId,
        array $made,
        null|string $resultAlreadyYoursHow = null,
        null|UuidInterface $resultAlreadyYoursId = null,
    ): void {
        $this->state = DataImportRowState::Added;
        $this->addedAt = $now;
        $this->failure = null;
        $this->collectionItemId = $collectionItemId;
        $this->wishListItemId = $wishListItemId;
        $this->made = $made;
        $this->resultMade = isset($made['result']);
        // A row that is a result and an owned puzzle may add the puzzle while its result is the player's already: the
        // result it stands for stays named, so no other row pairs off with it. Else nothing is hinted any more
        $this->alreadyYoursHow = $resultAlreadyYoursHow;
        $this->alreadyYoursSolvingTimeId = $resultAlreadyYoursId;
    }

    /**
     * Nothing to add: all the row says is the player's already. $how: `profile` (a result on the profile),
     * `earlier_import` (the same data in another import), `this_row` (the row's own result is still there),
     * `equal_row` (the same result as another row of the file), `owned` (in the collection / on the wish list).
     */
    public function alreadyYours(string $how, null|UuidInterface $solvingTimeId): void
    {
        $this->state = DataImportRowState::AlreadyYours;
        $this->failure = null;
        $this->alreadyYoursHow = $how;
        $this->alreadyYoursSolvingTimeId = $solvingTimeId;
    }

    /**
     * A rule said no (ImportRowRefusal / ImportedResultRefusal): the row waits again with the reason - it can be
     * fixed, answered and asked for again; "add everything that is ready" leaves it alone until then.
     */
    public function refused(string $code): void
    {
        $this->state = DataImportRowState::Waiting;
        $this->failure = $code;
    }

    /**
     * The row threw - ours. Never tried again by itself.
     */
    public function failed(string $code): void
    {
        $this->state = DataImportRowState::Failed;
        $this->failure = $code;
    }

    /**
     * Undo took back what the row made: it waits again as before its "Add" - it can be corrected (the row sheet takes
     * only a row that names no result) and added again. The link to its result goes with the result: the writer's
     * compare-and-set expects "nothing, or a result that is gone", and nothing is the clean state.
     */
    public function undone(): void
    {
        $this->solvingTimeId = null;
        $this->state = DataImportRowState::Waiting;
        $this->failure = null;
        $this->addedAt = null;
        $this->collectionItemId = null;
        $this->wishListItemId = null;
        $this->made = null;
        $this->resultMade = false;
        $this->undoId = null;
        $this->alreadyYoursHow = null;
        $this->alreadyYoursSolvingTimeId = null;
    }

    /**
     * An Undo came to the row and left it as it is: its result was changed since the import.
     */
    public function keptByUndo(UuidInterface $undoId): void
    {
        $this->made = [...($this->made ?? []), 'keptByUndo' => $undoId->toString()];
        $this->undoId = null;
    }

    /**
     * The custom fields the row filled on what it made (ids only), and how many values could not be stored.
     *
     * @param list<string> $onResult
     * @param list<string> $onCollectionItem
     */
    public function customFieldsFilled(array $onResult, array $onCollectionItem, int $leftOut): void
    {
        $this->made = [...($this->made ?? []), 'customFields' => ['result' => $onResult, 'collectionItem' => $onCollectionItem, 'leftOut' => $leftOut]];
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
        // A row that waits with the reason an "Add" did not take it and now says something else - another time, day,
        // kind or puzzle (its key) - is not that refused row any more: it is ready like any other and judged afresh
        // when it is added. So an answer for "all rows refused for X" never sweeps in a row that was corrected since
        if ($this->failure !== null && $this->state === DataImportRowState::Waiting && $record['sourceKey'] !== $this->sourceKey) {
            $this->failure = null;
        }

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
     * The player's word "add it anyway" to questions an "Add" asks about this row (ImportRowRefusal's values -
     * docs/features/data-import/adding.md "Refusals": a similar result exists, somebody else's result, the file says
     * it is on MySpeedPuzzling, an unusual time). **The word is given for what the row says now**: it is kept with a
     * fingerprint of the row's time, day, kind, people and the puzzle it is paired with (waiverFingerprint()), and
     * counts only while that still matches - a changed time, a mapping read differently, another puzzle ask again.
     * Kept with the row's other answers, so every later parse keeps it.
     *
     * @param list<string> $codes
     */
    public function addAnyway(array $codes, null|string $puzzleId): void
    {
        $answers = $this->answers ?? [];
        $waved = is_array($answers['addAnyway'] ?? null) ? $answers['addAnyway'] : [];
        $fingerprint = $this->waiverFingerprint($puzzleId);
        $kept = [];

        // Words given for another reading of the row are gone - they would never count again
        foreach ($waved as $code => $given) {
            if (is_string($code) && $given === $fingerprint) {
                $kept[$code] = $given;
            }
        }

        foreach ($codes as $code) {
            $kept[$code] = $fingerprint;
        }

        // The question the row waits with is answered: it is ready again like any other row ("Add" takes it with
        // everything that is ready - today, or tomorrow when today's guard is used up)
        if (in_array($this->failure, $codes, true)) {
            $this->failure = null;
        }

        $answers['addAnyway'] = $kept;
        $this->answers = $answers;
    }

    /**
     * Whether the player said "add it anyway" to this question - about the row as it is now.
     */
    public function isWavedThrough(string $code, null|string $puzzleId): bool
    {
        $waved = $this->answers['addAnyway'] ?? null;

        return is_array($waved) && ($waved[$code] ?? null) === $this->waiverFingerprint($puzzleId);
    }

    /**
     * What an "add it anyway" was said about: the row's time, day, kind and people as they were read, and the
     * catalogue puzzle the row is paired with.
     */
    public function waiverFingerprint(null|string $puzzleId): string
    {
        $parsed = $this->parsed ?? [];

        return md5(json_encode([
            $parsed['seconds'] ?? null,
            $parsed['date'] ?? null,
            $parsed['datePrecision'] ?? null,
            $parsed['type'] ?? null,
            $parsed['people'] ?? [],
            $puzzleId === null ? null : strtolower($puzzleId),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * "One result or two?" (README D6): a row that is the same result as another row of the file is counted on its
     * own when the player says so - and as that row's result again when they take it back.
     */
    public function countOnItsOwn(bool $separate): void
    {
        $this->addAsSeparate = $separate && $this->sameAsRow !== null;
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
