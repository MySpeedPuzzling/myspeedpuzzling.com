<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

/**
 * Who organises events (docs/features/navigation README §4.12 "Menu data" 2, MENU-01): one rule shared by the viewer's
 * profile row - whether the Menu shows "Events you organize" (GetPlayerProfile::byUserId(), no statement of its own) -
 * and GetEventsViewerData, whose "You organize (n)" counts the same things. A player organises when they created or
 * maintain an event, a series or an organization; an organization's team organises everything under it, so being on one
 * team is enough. Drafts, items waiting for approval and rejected ones count on purpose: they are the organiser's own
 * (DraftVisibilityCoverageTest lists both readers as the viewer's own rows). OrganizerSqlParityTest pins
 * organizesAnything() to EventsViewerData::organizedCount() > 0.
 */
final readonly class OrganizerSql
{
    /**
     * A boolean SQL expression: does the player organise anything? Six index probes (one per table, each table has an
     * index on its player column), short-circuited by OR.
     *
     * @param string $playerId the SQL expression of the player's id, e.g. `player.id` or `:playerId`
     */
    public static function organizesAnything(string $playerId): string
    {
        return <<<SQL
(
        EXISTS (SELECT 1 FROM competition organizer_c WHERE organizer_c.added_by_player_id = {$playerId})
        OR EXISTS (SELECT 1 FROM competition_maintainer organizer_cm WHERE organizer_cm.player_id = {$playerId})
        OR EXISTS (SELECT 1 FROM competition_series organizer_cs WHERE organizer_cs.added_by_player_id = {$playerId})
        OR EXISTS (SELECT 1 FROM competition_series_maintainer organizer_csm WHERE organizer_csm.player_id = {$playerId})
        OR EXISTS (SELECT 1 FROM organization organizer_o WHERE organizer_o.added_by_player_id = {$playerId})
        OR EXISTS (SELECT 1 FROM organization_maintainer organizer_om WHERE organizer_om.player_id = {$playerId})
    )
SQL;
    }

    /**
     * The organizations whose team the player is on - they created it or maintain it (the last two legs of
     * organizesAnything()), as a subquery for `IN`.
     *
     * @param string $playerId the SQL expression of the player's id, e.g. `:playerId`
     */
    public static function teamOrganizationIds(string $playerId): string
    {
        return <<<SQL
(SELECT team_o.id FROM organization team_o WHERE team_o.added_by_player_id = {$playerId}
    UNION SELECT team_om.organization_id FROM organization_maintainer team_om WHERE team_om.player_id = {$playerId})
SQL;
    }
}
