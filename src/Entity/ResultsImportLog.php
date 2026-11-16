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

/**
 * One "Import" on the results import page (docs/features/event-results-import/results-import-page.md "As built: PR 3"):
 * who, when, from which check, and what became of every ticked event. Append-only. Its id is the import's - minted on
 * the confirmation page, so a second submit of the same confirmation imports nothing twice. The events' competitions
 * and the check are copied ids, no foreign keys: a deleted event or a pruned check leaves the history as it was.
 */
#[Entity]
#[Index(columns: ['source_id', 'imported_at'])]
class ResultsImportLog
{
    /**
     * @param list<array{key: string, title: string, outcome: string, competition_id: null|string, added: int, updated: int, removed: int}> $events
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public EventResultsSheet $source,
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $checkId,
        // Who pressed "Import" - null once their account is gone
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Player $importedBy,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $importedAt,
        #[Immutable]
        #[Column(type: Types::JSON, options: ['jsonb' => true])]
        public array $events,
        #[Immutable]
        #[Column]
        public int $teamsAdded,
        #[Immutable]
        #[Column]
        public int $teamsUpdated,
        #[Immutable]
        #[Column]
        public int $teamsRemoved,
    ) {
    }
}
