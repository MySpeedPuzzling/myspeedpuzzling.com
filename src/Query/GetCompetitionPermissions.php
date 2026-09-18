<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CompetitionPermissions;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Everything one player may manage, in a single query per request: the event
 * listings ask the voters about every card they render, so a per-id lookup
 * would be an N+1 (Sentry WEB-BZ). The set is small - only what the player
 * owns or maintains - so it is loaded whole and cached until the next request.
 */
final class GetCompetitionPermissions implements ResetInterface
{
    /** @var array<string, CompetitionPermissions> */
    private array $cache = [];

    public function __construct(
        private readonly Connection $database,
    ) {
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    public function forPlayer(string $playerId): CompetitionPermissions
    {
        if (isset($this->cache[$playerId])) {
            return $this->cache[$playerId];
        }

        $query = <<<SQL
SELECT 'competition' AS kind, id::text AS id, true AS owner FROM competition WHERE added_by_player_id = :playerId
UNION ALL
SELECT 'competition', competition_id::text, false FROM competition_maintainer WHERE player_id = :playerId
UNION ALL
SELECT 'competition', c.id::text, true FROM competition c INNER JOIN competition_series cs ON cs.id = c.series_id WHERE cs.added_by_player_id = :playerId
UNION ALL
SELECT 'competition', c.id::text, false FROM competition c INNER JOIN competition_series_maintainer csm ON csm.competition_series_id = c.series_id WHERE csm.player_id = :playerId
UNION ALL
SELECT 'series', id::text, true FROM competition_series WHERE added_by_player_id = :playerId
UNION ALL
SELECT 'series', competition_series_id::text, false FROM competition_series_maintainer WHERE player_id = :playerId
SQL;

        /** @var list<array{kind: string, id: string, owner: bool}> $rows */
        $rows = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchAllAssociative();

        $editableCompetitionIds = [];
        $deletableCompetitionIds = [];
        $editableSeriesIds = [];
        $deletableSeriesIds = [];

        foreach ($rows as $row) {
            if ($row['kind'] === 'competition') {
                $editableCompetitionIds[$row['id']] = true;

                if ($row['owner'] === true) {
                    $deletableCompetitionIds[$row['id']] = true;
                }

                continue;
            }

            $editableSeriesIds[$row['id']] = true;

            if ($row['owner'] === true) {
                $deletableSeriesIds[$row['id']] = true;
            }
        }

        $permissions = new CompetitionPermissions(
            editableCompetitionIds: $editableCompetitionIds,
            deletableCompetitionIds: $deletableCompetitionIds,
            editableSeriesIds: $editableSeriesIds,
            deletableSeriesIds: $deletableSeriesIds,
        );

        $this->cache[$playerId] = $permissions;

        return $permissions;
    }
}
