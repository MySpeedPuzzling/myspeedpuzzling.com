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
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshot;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshotTab;
use SpeedPuzzling\Web\Value\EventResultsImport\UnreadableSheetTab;

/**
 * One "Check sheet" of a results source on the results import page (docs/features/event-results-import/
 * results-import-page.md "Three layers", 3.): the sheet exactly as it was read - every tab's CSV, never parsed events.
 * The page parses it through the source's format each time it shows it, and an import applies the events parsed from
 * it - never a new read: what the organizer saw is what is published. Null snapshot = the sheet could not be read
 * ($readError says why).
 */
#[Entity]
#[Index(columns: ['source_id', 'checked_at'])]
class ResultsImportCheck
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public EventResultsSheet $source,
        // Who pressed "Check sheet" - null once their account is gone
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Player $checkedBy,
        // When the sheet was read - an import from this check refuses an event applied after it (a newer check won)
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $checkedAt,
        /** @var null|array{tabs: list<array{gid: string, title: string, csv: string}>, unreadable: list<array{gid: string, title: string, reason: string}>} */
        #[Immutable]
        #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
        public null|array $snapshot,
        #[Immutable]
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $readError = null,
    ) {
    }

    public static function read(
        UuidInterface $id,
        EventResultsSheet $source,
        null|Player $checkedBy,
        DateTimeImmutable $checkedAt,
        SheetSnapshot $snapshot,
    ): self {
        return new self($id, $source, $checkedBy, $checkedAt, [
            'tabs' => array_map(static fn (SheetSnapshotTab $tab): array => ['gid' => $tab->gid, 'title' => $tab->title, 'csv' => $tab->csv], $snapshot->tabs),
            'unreadable' => array_map(static fn (UnreadableSheetTab $tab): array => ['gid' => $tab->gid, 'title' => $tab->title, 'reason' => $tab->reason], $snapshot->unreadableTabs),
        ]);
    }

    public static function failed(
        UuidInterface $id,
        EventResultsSheet $source,
        null|Player $checkedBy,
        DateTimeImmutable $checkedAt,
        string $error,
    ): self {
        return new self($id, $source, $checkedBy, $checkedAt, null, $error);
    }

    /**
     * The sheet as it was read - null when it could not be read.
     */
    public function sheetSnapshot(): null|SheetSnapshot
    {
        if ($this->snapshot === null) {
            return null;
        }

        return new SheetSnapshot(
            array_map(static fn (array $tab): SheetSnapshotTab => new SheetSnapshotTab($tab['gid'], $tab['title'], $tab['csv']), $this->snapshot['tabs']),
            array_map(static fn (array $tab): UnreadableSheetTab => new UnreadableSheetTab($tab['gid'], $tab['title'], $tab['reason']), $this->snapshot['unreadable']),
        );
    }
}
