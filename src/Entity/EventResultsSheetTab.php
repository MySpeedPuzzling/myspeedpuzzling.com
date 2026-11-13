<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabProblem;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabStatus;

/**
 * One tab of an organizer's results sheet and what became of it (docs/features/event-results-import/README.md
 * "Tab outcomes"). Keyed by Google's gid, which survives a rename of the tab.
 */
#[Entity]
#[UniqueConstraint(columns: ['sheet_id', 'gid'])]
class EventResultsSheetTab
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, enumType: EventTabStatus::class)]
    public EventTabStatus $status = EventTabStatus::Problem;

    /** @var list<array{code: string, detail: null|string, line: null|int}> */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $problems = [];

    // Of the parsed tab - unchanged means nothing to apply
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 40, nullable: true)]
    public null|string $contentHash = null;

    // The event made from this tab; null before the first import and after the event was deleted (detached)
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public null|Competition $competition = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $firstImportedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $appliedAt = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public EventResultsSheet $sheet,
        #[Immutable]
        #[Column(length: 20)]
        public string $gid,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $title,
    ) {
    }

    public function isDetached(): bool
    {
        return $this->firstImportedAt !== null && $this->competition === null;
    }

    /**
     * @param list<EventTabProblem> $problems
     * @return bool whether the status or the problems changed - only then is the organizer warned again
     */
    public function record(
        string $title,
        EventTabStatus $status,
        array $problems,
        string $contentHash,
        null|Competition $competition,
        DateTimeImmutable $at,
    ): bool {
        $problems = array_map(static fn (EventTabProblem $problem): array => $problem->toArray(), $problems);
        $changed = $status !== $this->status || $problems !== $this->problems;

        $this->title = $title;
        $this->status = $status;
        $this->problems = $problems;
        $this->contentHash = $contentHash;
        $this->appliedAt = $at;

        if ($competition !== null) {
            $this->competition = $competition;
            $this->firstImportedAt ??= $at;
        }

        return $changed;
    }
}
