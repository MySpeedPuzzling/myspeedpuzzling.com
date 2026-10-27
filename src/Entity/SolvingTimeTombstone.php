<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * "This result was deleted, at this moment" - one row per registered member of a deleted solving time, so an API
 * client keeping a copy of its user's results learns about deletions (GET /api/v1/me/solving-times/deleted, the
 * lookup's `deleted`; docs/features/api/solving-time-sync.md). Only ids and the moment, nothing of the result.
 *
 * Plain ids without FKs (the time is gone); removed again when the time comes back (Undo of an automatic removal),
 * on account deletion and by the daily prune after 24 months.
 */
#[Entity]
#[Index(columns: ['player_id', 'deleted_at', 'solving_time_id'])]
#[Index(columns: ['solving_time_id'])]
class SolvingTimeTombstone
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $solvingTimeId,
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $playerId,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $deletedAt,
    ) {
    }
}
