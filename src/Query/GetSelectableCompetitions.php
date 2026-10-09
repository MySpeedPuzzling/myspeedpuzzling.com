<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\SelectableCompetition;
use SpeedPuzzling\Web\Value\CompetitionPick;
use SpeedPuzzling\Web\Value\CompetitionPickKind;

/**
 * What the "Competition / event" picker on the add-time and edit-time forms offers
 * (docs/features/events-page/high-frequency-series.md "The default list") - one statement:
 *
 * - every publicly visible ONE-TIME event (`IsCompetitionPubliclyVisible::SQL_CONDITION`), whatever its date;
 * - every publicly visible SERIES, one entry each (IsSeriesPubliclyVisible) - MySpeedPuzzling finds the edition. Its
 *   days come from its publicly visible editions (SeriesEditionDays), its organization's names only from a publicly
 *   visible organization;
 * - NO edition: a series with 200 editions adds nothing to the page. An edition is found by typing
 *   (GetSeriesEditionChoices::search()) or picked from the preview's short list. It is offered here only as the
 *   current pick (`$alwaysInclude`: the edited time's link or a deep link - even when it is no longer public) or as a
 *   refused submit's own choice (`$submittedEditionId`, only while publicly visible), so the control shows it again.
 *
 * `$alwaysInclude` also keeps a no longer public one-time event or series of the edited time offered, so a re-save
 * keeps the link.
 *
 * Global order: live (a live one-time event, a series with a live edition) → undated one-time events ("perpetual"
 * online umbrellas) → past, newest first (a series by its latest past edition's day) → upcoming, soonest first →
 * series without any dated edition. An included edition sorts right after its series. Undated one-time events that
 * have rounds are dated by their first round.
 *
 * @phpstan-import-type SelectableCompetitionDatabaseRow from SelectableCompetition
 */
readonly final class GetSelectableCompetitions
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<SelectableCompetition>
     */
    public function all(null|CompetitionPick $alwaysInclude = null, null|string $submittedEditionId = null): array
    {
        if ($submittedEditionId !== null && Uuid::isValid($submittedEditionId) === false) {
            $submittedEditionId = null;
        }

        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;
        $seriesVisible = IsSeriesPubliclyVisible::SQL_CONDITION;
        $organizationVisible = IsOrganizationPubliclyVisible::SQL_CONDITION;
        $seriesDays = SeriesEditionDays::sqlJoin('sed', ':today');

        $effectiveFrom = 'COALESCE(c.date_from, c.date_to, r.first_round_at)';
        $eventStatus = <<<SQL
CASE
    WHEN {$effectiveFrom} IS NULL THEN 'undated'
    WHEN CAST(:today AS DATE) BETWEEN CAST({$effectiveFrom} AS DATE) AND CAST(COALESCE(c.date_to, c.date_from, r.first_round_at) AS DATE) THEN 'live'
    WHEN CAST({$effectiveFrom} AS DATE) > CAST(:today AS DATE) THEN 'upcoming'
    ELSE 'past'
END
SQL;
        // A series sorts by its editions: live while one is, else by its latest past one, else by its next one
        $seriesStatus = "CASE WHEN sed.has_live THEN 'live' WHEN sed.last_past_day IS NOT NULL THEN 'past' WHEN sed.next_day IS NOT NULL THEN 'upcoming' ELSE 'undated' END";
        $seriesBucket = 'CASE WHEN sed.has_live THEN 1 WHEN sed.last_past_day IS NOT NULL THEN 3 WHEN sed.next_day IS NOT NULL THEN 4 ELSE 5 END';
        $seriesSortAt = 'CAST(CASE WHEN sed.has_live THEN CAST(:today AS DATE) WHEN sed.last_past_day IS NOT NULL THEN sed.last_past_day ELSE sed.next_day END AS TIMESTAMP)';
        $firstRound = 'LEFT JOIN LATERAL (SELECT MIN(starts_at) AS first_round_at FROM competition_round WHERE competition_id = c.id) r ON true';

        $query = <<<SQL
SELECT s.kind, s.id, s.name, s.shortcut, s.logo, s.series_logo, s.location, s.location_country_code, s.date_from,
    s.date_to, s.is_online, s.series_id, s.series_name, s.series_shortcut, s.event_status, s.next_day, s.last_day,
    s.edition_count, s.dated_edition_count, s.organization_name, s.organization_short_name
FROM (
    SELECT 'event' AS kind,
        c.id,
        c.name,
        c.shortcut,
        c.logo,
        CAST(NULL AS VARCHAR) AS series_logo,
        c.location,
        c.location_country_code,
        c.date_from,
        c.date_to,
        c.is_online,
        CAST(NULL AS UUID) AS series_id,
        CAST(NULL AS VARCHAR) AS series_name,
        CAST(NULL AS VARCHAR) AS series_shortcut,
        {$eventStatus} AS event_status,
        CAST(NULL AS DATE) AS next_day,
        CAST(NULL AS DATE) AS last_day,
        CAST(0 AS BIGINT) AS edition_count,
        CAST(0 AS BIGINT) AS dated_edition_count,
        CAST(NULL AS VARCHAR) AS organization_name,
        CAST(NULL AS VARCHAR) AS organization_short_name,
        CASE
            WHEN {$effectiveFrom} IS NULL THEN 2
            WHEN CAST(:today AS DATE) BETWEEN CAST({$effectiveFrom} AS DATE) AND CAST(COALESCE(c.date_to, c.date_from, r.first_round_at) AS DATE) THEN 1
            WHEN CAST({$effectiveFrom} AS DATE) > CAST(:today AS DATE) THEN 4
            ELSE 3
        END AS sort_bucket,
        CAST({$effectiveFrom} AS TIMESTAMP) AS sort_at,
        c.name AS sort_name,
        c.id AS sort_group,
        0 AS sort_kind
    FROM competition c
    LEFT JOIN competition_series cs ON cs.id = c.series_id
    {$firstRound}
    WHERE c.series_id IS NULL
        AND ({$visible} OR c.id = :includeEventId)

    UNION ALL

    SELECT 'series' AS kind,
        cs.id,
        cs.name,
        cs.shortcut,
        cs.logo,
        cs.logo AS series_logo,
        cs.location,
        cs.location_country_code,
        NULL AS date_from,
        NULL AS date_to,
        cs.is_online,
        cs.id AS series_id,
        cs.name AS series_name,
        cs.shortcut AS series_shortcut,
        {$seriesStatus} AS event_status,
        sed.next_day,
        sed.last_past_day AS last_day,
        sed.edition_count,
        sed.dated_edition_count,
        o.name AS organization_name,
        o.short_name AS organization_short_name,
        {$seriesBucket} AS sort_bucket,
        {$seriesSortAt} AS sort_at,
        cs.name AS sort_name,
        cs.id AS sort_group,
        0 AS sort_kind
    FROM competition_series cs
    {$seriesDays}
    LEFT JOIN organization o ON o.id = cs.organization_id AND {$organizationVisible}
    WHERE {$seriesVisible} OR cs.id = :includeSeriesId

    UNION ALL

    SELECT 'edition' AS kind,
        c.id,
        c.name,
        c.shortcut,
        COALESCE(c.logo, cs.logo),
        cs.logo AS series_logo,
        COALESCE(c.location, cs.location),
        COALESCE(c.location_country_code, cs.location_country_code),
        c.date_from,
        c.date_to,
        c.is_online,
        cs.id AS series_id,
        cs.name AS series_name,
        cs.shortcut AS series_shortcut,
        {$eventStatus} AS event_status,
        NULL AS next_day,
        NULL AS last_day,
        0 AS edition_count,
        0 AS dated_edition_count,
        NULL AS organization_name,
        NULL AS organization_short_name,
        {$seriesBucket} AS sort_bucket,
        {$seriesSortAt} AS sort_at,
        cs.name AS sort_name,
        cs.id AS sort_group,
        1 AS sort_kind
    FROM competition c
    INNER JOIN competition_series cs ON cs.id = c.series_id
    {$firstRound}
    {$seriesDays}
    WHERE c.id = :includeEditionId
        OR (c.id = :submittedEditionId AND {$visible})
) s
ORDER BY
    s.sort_bucket,
    CASE WHEN s.sort_bucket = 3 THEN s.sort_at END DESC NULLS LAST,
    CASE WHEN s.sort_bucket IN (1, 4) THEN s.sort_at END ASC NULLS LAST,
    s.sort_name ASC,
    s.sort_group ASC,
    s.sort_kind ASC,
    s.id ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'today' => $this->clock->now()->format('Y-m-d'),
                'includeEventId' => $alwaysInclude?->kind === CompetitionPickKind::Event ? $alwaysInclude->id : null,
                'includeSeriesId' => $alwaysInclude?->kind === CompetitionPickKind::Series ? $alwaysInclude->id : null,
                'includeEditionId' => $alwaysInclude?->kind === CompetitionPickKind::Edition ? $alwaysInclude->id : null,
                'submittedEditionId' => $submittedEditionId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): SelectableCompetition {
            /** @var SelectableCompetitionDatabaseRow $row */
            return SelectableCompetition::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * A deep link's competition (`?competition=`), as the picker offers it: a publicly visible one-time event, or an
     * explicit edition of a publicly visible series - null for anything else. One statement.
     */
    public function publicPick(string $competitionId): null|CompetitionPick
    {
        if (Uuid::isValid($competitionId) === false) {
            return null;
        }

        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;

        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT c.series_id
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE c.id = :competitionId
    AND {$visible}
SQL,
            ['competitionId' => $competitionId],
        );

        if ($row === false) {
            return null;
        }

        return $row['series_id'] === null ? CompetitionPick::event($competitionId) : CompetitionPick::edition($competitionId);
    }
}
