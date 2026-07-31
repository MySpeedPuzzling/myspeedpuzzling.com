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
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * One row per player per UTC day - the raw presence fact behind DAU/WAU/MAU
 * and retention analytics (docs/features/activity-analytics.md). Deliberately
 * coarse: no IP, no user agent, no paths - presence only. Rows are written via
 * ON CONFLICT DO NOTHING upsert and pruned after 24 months; long-term trends
 * live in the immortal activity_daily_summary aggregates.
 */
#[Entity]
#[Table(name: 'player_activity_day')]
#[UniqueConstraint(columns: ['player_id', 'day'])]
#[Index(columns: ['day'])]
class PlayerActivityDay
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        // GDPR: deleting the player takes their activity trail with it
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        #[Immutable]
        #[Column(type: Types::DATE_IMMUTABLE)]
        public DateTimeImmutable $day,
        #[Immutable]
        #[Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $firstSeenAt,
    ) {
    }
}
