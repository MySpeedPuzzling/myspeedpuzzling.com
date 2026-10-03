<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;
use JetBrains\PhpStorm\Immutable;

/**
 * Precomputed activity of one player - what the Players page lists people by (docs/features/players-page/README.md).
 * Written only by the community stats cron in one bulk statement (RecalculateCommunityStatsHandler); nothing in the
 * app changes a row, so the entity exists for the schema alone. A "result" is a solving time the player took part
 * in: tracked solo or as a registered member of a pair/team.
 */
#[Entity]
#[Table(name: 'community_player_stats')]
class CommunityPlayerStats
{
    /**
     * @param list<int> $monthlySolves results per calendar month (UTC), 12 months, oldest first, the current month last
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[OneToOne]
        #[JoinColumn(onDelete: 'CASCADE')]
        public Player $player,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $solvedTotal,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $piecesTotal,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $solves7d,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $pieces7d,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $solves30d,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $solvesPrev30d,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $solvesThisMonth,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $piecesThisMonth,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $solvesLastMonth,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $piecesLastMonth,
        // Best solo time on exactly 500 / 1000 pieces, suspicious times left out
        #[Immutable]
        #[Column(type: Types::INTEGER, nullable: true)]
        public null|int $best500Seconds,
        #[Immutable]
        #[Column(type: Types::INTEGER, nullable: true)]
        public null|int $best1000Seconds,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $firstSolvedAt,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $lastSolvedAt,
        #[Immutable]
        #[Column(type: Types::JSONB)]
        public array $monthlySolves,
        // How many players have this one in favorites - the count only, never who
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $favoritesCount,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $computedAt,
    ) {
    }
}
