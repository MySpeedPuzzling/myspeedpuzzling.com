<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\EventsViewerData;

/**
 * The viewer's own relation to events in one statement (docs/features/events-page/implementation-plan.md, 1.4):
 * going (the going rule), followed events and series, organised events and series (created or maintained -
 * waiting for approval and rejected ones included). Only ids, never other players.
 */
readonly final class GetEventsViewerData
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forPlayer(string $playerId): EventsViewerData
    {
        if (Uuid::isValid($playerId) === false) {
            return new EventsViewerData();
        }

        $going = CompetitionParticipantGoing::sql('cp');

        $query = <<<SQL
SELECT 'going' AS kind, cp.competition_id::text AS id, NULL::text AS series_id
FROM competition_participant cp
WHERE cp.player_id = :playerId AND {$going}
UNION ALL
SELECT 'follow_competition', fc.competition_id::text, NULL
FROM followed_competition fc
WHERE fc.player_id = :playerId AND fc.competition_id IS NOT NULL
UNION ALL
SELECT 'follow_series', fc.series_id::text, NULL
FROM followed_competition fc
WHERE fc.player_id = :playerId AND fc.series_id IS NOT NULL
UNION ALL
SELECT 'organize_competition', c.id::text, c.series_id::text
FROM competition c
WHERE c.added_by_player_id = :playerId
    OR EXISTS (SELECT 1 FROM competition_maintainer cm WHERE cm.competition_id = c.id AND cm.player_id = :playerId)
UNION ALL
SELECT 'organize_series', cs.id::text, NULL
FROM competition_series cs
WHERE cs.added_by_player_id = :playerId
    OR EXISTS (SELECT 1 FROM competition_series_maintainer csm WHERE csm.competition_series_id = cs.id AND csm.player_id = :playerId)
SQL;

        $going = [];
        $followedCompetitions = [];
        $followedSeries = [];
        $organizedCompetitions = [];
        $organizedSeries = [];

        /** @var array<string, null|string|int|bool> $row */

        foreach ($this->database->executeQuery($query, ['playerId' => $playerId])->fetchAllAssociative() as $row) {
            $id = strtolower((string) $row['id']);
            $seriesId = is_string($row['series_id']) ? strtolower($row['series_id']) : null;

            match ($row['kind']) {
                'going' => $going[$id] = $id,
                'follow_competition' => $followedCompetitions[$id] = $id,
                'follow_series' => $followedSeries[$id] = $id,
                'organize_competition' => $organizedCompetitions[$id] = $seriesId,
                'organize_series' => $organizedSeries[$id] = $id,
                default => null,
            };
        }

        return new EventsViewerData(
            goingCompetitionIds: array_values($going),
            followedCompetitionIds: array_values($followedCompetitions),
            followedSeriesIds: array_values($followedSeries),
            organizedCompetitions: $organizedCompetitions,
            organizedSeriesIds: array_values($organizedSeries),
        );
    }
}
