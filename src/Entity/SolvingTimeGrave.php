<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\Table;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\SolvingTimeDeletedVia;

/**
 * A deleted solving time, kept whole for disaster recovery - backups go back 7 days only
 * (docs/features/api/solving-time-sync.md "Graveyard"). Written by PuzzleSolvingTimeRepository::delete() in the
 * deleting transaction, **never read by the app**: a restore is the runbook in the doc.
 *
 * Ids are plain columns without FKs - the grave outlives the time, its puzzle and its players. Account deletion
 * wipes the player's graves (erasure), the daily prune everything older than 24 months.
 */
#[Entity]
#[Table(name: 'solving_time_graveyard')]
#[Index(columns: ['player_id'])]
#[Index(columns: ['deleted_at'])]
class SolvingTimeGrave
{
    /**
     * @param array<string, mixed> $snapshot see RemovedResultSnapshot::toArray()
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $solvingTimeId,
        // The tracker
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $playerId,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $deletedAt,
        #[Immutable]
        #[Column(length: 30, enumType: SolvingTimeDeletedVia::class)]
        public SolvingTimeDeletedVia $deletedVia,
        #[Immutable]
        #[Column(type: Types::JSON, options: ['jsonb' => true])]
        public array $snapshot,
    ) {
    }
}
