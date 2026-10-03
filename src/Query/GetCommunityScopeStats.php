<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CommunityScopeStatistics;
use SpeedPuzzling\Web\Value\CommunityScope;

/**
 * Reads community_scope_stats (docs/features/players-page/README.md). Aggregates without player identity - no
 * blocklist or private-profile filtering applies.
 */
readonly final class GetCommunityScopeStats
{
    private const string COLUMNS = 'scope, registered_players, active30d, solves30d, solves_prev30d, active_this_month, pieces_this_month, active_last_month, pieces_last_month, median_best500_seconds, puzzlers_with500, monthly_solves, new_faces14d, computed_at';

    public function __construct(
        private Connection $database,
    ) {
    }

    public function forScope(CommunityScope $scope): CommunityScopeStatistics
    {
        $row = $this->database
            ->executeQuery('SELECT ' . self::COLUMNS . ' FROM community_scope_stats WHERE scope = :scope', ['scope' => $scope->key()])
            ->fetchAssociative();

        if ($row === false) {
            return CommunityScopeStatistics::empty($scope);
        }

        /** @var array{scope: string, registered_players: int|string, active30d: int|string, solves30d: int|string, solves_prev30d: int|string, active_this_month: int|string, pieces_this_month: int|string, active_last_month: int|string, pieces_last_month: int|string, median_best500_seconds: null|int|string, puzzlers_with500: int|string, monthly_solves: string, new_faces14d: int|string, computed_at: string} $row */
        return CommunityScopeStatistics::fromDatabaseRow($row);
    }

    /**
     * The scope's numbers and the world's in one statement - the spotlight sets a country against the world. For the
     * world both are the same row; a scope nobody computed yet is empty.
     *
     * @return array{scope: CommunityScopeStatistics, world: CommunityScopeStatistics}
     */
    public function forScopeWithWorld(CommunityScope $scope): array
    {
        $rows = $this->database
            ->executeQuery(
                'SELECT ' . self::COLUMNS . ' FROM community_scope_stats WHERE scope IN (:scope, :world)',
                ['scope' => $scope->key(), 'world' => CommunityScope::WORLD],
            )
            ->fetchAllAssociative();

        $byScope = [];

        foreach ($rows as $row) {
            /** @var array{scope: string, registered_players: int|string, active30d: int|string, solves30d: int|string, solves_prev30d: int|string, active_this_month: int|string, pieces_this_month: int|string, active_last_month: int|string, pieces_last_month: int|string, median_best500_seconds: null|int|string, puzzlers_with500: int|string, monthly_solves: string, new_faces14d: int|string, computed_at: string} $row */
            $byScope[$row['scope']] = CommunityScopeStatistics::fromDatabaseRow($row);
        }

        return [
            'scope' => $byScope[$scope->key()] ?? CommunityScopeStatistics::empty($scope),
            'world' => $byScope[CommunityScope::WORLD] ?? CommunityScopeStatistics::empty(CommunityScope::world()),
        ];
    }

    /**
     * Every country with at least one registered player, most registered first. Rows whose code is not a known
     * country are left out.
     *
     * @return list<CommunityScopeStatistics>
     */
    public function countries(): array
    {
        $rows = $this->database
            ->executeQuery('SELECT ' . self::COLUMNS . " FROM community_scope_stats WHERE scope <> 'world' ORDER BY registered_players DESC, scope")
            ->fetchAllAssociative();

        $countries = [];

        foreach ($rows as $row) {
            /** @var array{scope: string, registered_players: int|string, active30d: int|string, solves30d: int|string, solves_prev30d: int|string, active_this_month: int|string, pieces_this_month: int|string, active_last_month: int|string, pieces_last_month: int|string, median_best500_seconds: null|int|string, puzzlers_with500: int|string, monthly_solves: string, new_faces14d: int|string, computed_at: string} $row */
            $statistics = CommunityScopeStatistics::fromDatabaseRow($row);

            if ($statistics->country() !== null) {
                $countries[] = $statistics;
            }
        }

        return $countries;
    }
}
