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
 * A copy of a result removed automatically because it was certainly the same result saved twice (Tier A,
 * docs/features/duplicate-results.md). Keeps everything needed to bring the row back with its own id (Undo),
 * and points links to the removed id at the kept copy.
 *
 * The result and case ids are plain columns without FKs, like result_duplicate_case - the row outlives both.
 */
#[Entity]
#[Index(columns: ['player_id', 'removed_at'])]
#[Index(columns: ['removed_time_id'])]
class ResultAutoRemoval
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $undoneAt = null;

    // When the player was told in the "Your results" e-mail (P5)
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $reportedAt = null;

    /**
     * @param array<string, mixed> $snapshot see RemovedResultSnapshot::toArray()
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        // The tracker of the removed copy - the only one who may bring it back
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $removedTimeId,
        // The older copy, which stays
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $keptTimeId,
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $caseId,
        #[Immutable]
        #[Column(type: Types::JSON)]
        public array $snapshot,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $removedAt,
    ) {
    }

    public function isUndone(): bool
    {
        return $this->undoneAt !== null;
    }

    public function undo(DateTimeImmutable $now): void
    {
        $this->undoneAt = $now;
    }
}
