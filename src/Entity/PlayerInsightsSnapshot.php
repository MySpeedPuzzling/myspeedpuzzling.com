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

/**
 * One player's Insights at the start of a month - strengths, weak spots, form, consistency, rating (a small JSON,
 * InsightsSnapshotFactory::VERSION) - so "what changed since last month" can be told later. Self-only data. Written
 * monthly by `myspeedpuzzling:snapshot-player-insights` (docs/features/insights/computation.md "Core sections" §12),
 * set-based (PlayerInsightsSnapshotWriter); a re-run in the same month replaces the month's row.
 */
#[Entity]
#[UniqueConstraint(columns: ['player_id', 'month'])]
class PlayerInsightsSnapshot
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        // The first day of the month the snapshot is of
        #[Immutable]
        #[Column(type: Types::DATE_IMMUTABLE)]
        public DateTimeImmutable $month,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSONB)]
        public array $data,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $computedAt,
    ) {
    }
}
