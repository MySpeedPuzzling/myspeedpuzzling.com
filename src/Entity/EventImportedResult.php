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
        // The row's name as the sheet writes it (≤ 50, like puzzling_team.name) - a single person's display name while
        // nobody has claimed the result (solo-and-relays.md §1); written for every row, null for rows imported before
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 50, nullable: true)]
        public null|string $name = null,
    ) {
    }

    /**
     * @return bool whether it changed
     */
    public function nameFromSheet(string $name): bool
    {
        if ($this->name === $name) {
            return false;
        }

        $this->name = $name;

        return true;
    }
}
