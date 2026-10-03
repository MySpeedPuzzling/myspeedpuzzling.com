<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use JetBrains\PhpStorm\Immutable;

/**
 * Precomputed numbers of one community scope - the world or one country (CommunityScope::key()) - for the Players
 * page's spotlight, the country tiles and the Country Cup (docs/features/players-page/README.md). Aggregates without
 * identity: private players count, blocks do not apply. Written only by the community stats cron.
 */
#[Entity]
#[Table(name: 'community_scope_stats')]
class CommunityScopeStats
{
    /**
     * @param list<int> $monthlySolves person-results per calendar month (UTC), 12 months, oldest first
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(length: 10)]
        public string $scope,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $registeredPlayers,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $active30d,
        // Person-results: a pair's result counts once for each of its registered members
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $solves30d,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $solvesPrev30d,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $activeThisMonth,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $piecesThisMonth,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $activeLastMonth,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $piecesLastMonth,
        #[Immutable]
        #[Column(type: Types::INTEGER, nullable: true)]
        public null|int $medianBest500Seconds,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $puzzlersWith500,
        #[Immutable]
        #[Column(type: Types::JSON, options: ['jsonb' => true])]
        public array $monthlySolves,
        // Public players registered in the last 14 days with at least one result
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $newFaces14d,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $computedAt,
    ) {
    }
}
