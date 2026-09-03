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
use SpeedPuzzling\Web\Value\XpReason;

/**
 * One XP-ledger line — the user-visible receipt renders 1:1 from these rows.
 *
 * References are plain uuid columns without FKs on purpose: a deleted solve leaves its
 * (compensated) ledger history behind, so solving_time_id must be allowed to dangle.
 *
 * earned_at semantics (critical for the weekly-delta leaderboard): solve-derived entries
 * carry the SOLVE's timestamp (COALESCE(finished_at, tracked_at)), achievement entries the
 * badge's earned_at, settlement entries the settlement run time (they are excluded from the
 * weekly delta anyway). Never clock-now for solve-derived entries — backfill would otherwise
 * dump the entire history into the launch week's delta.
 */
#[Entity]
#[Index(columns: ['player_id', 'earned_at'])]
#[Index(columns: ['solving_time_id'])]
class XpEntry
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $playerId,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $amount,
        #[Immutable]
        #[Column]
        public XpReason $reason,
        #[Immutable]
        #[Column(type: Types::BOOLEAN)]
        public bool $inWeeklyDelta,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $earnedAt,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        #[Immutable]
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $solvingTimeId = null,
        #[Immutable]
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $badgeId = null,
    ) {
    }
}
