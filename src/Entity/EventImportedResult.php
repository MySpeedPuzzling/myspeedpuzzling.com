<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * Which solving time a team's row of a results tab became - the import's upsert key
 * (docs/features/event-results-import/README.md "Data model"). The team's folded name is its identity: a renamed team
 * is a removed row and a new one. Goes with the solving time.
 */
#[Entity]
#[UniqueConstraint(columns: ['tab_id', 'team_key'])]
class EventImportedResult
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public EventResultsSheetTab $tab,
        #[Immutable]
        #[Column]
        public string $teamKey,
        #[Immutable]
        #[OneToOne]
        #[JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
        public PuzzleSolvingTime $solvingTime,
    ) {
    }
}
