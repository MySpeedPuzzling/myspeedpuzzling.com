<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\SelectableCompetition;

/**
 * The set of competitions a player may link a solving time to — what the "Competition / event" picker
 * on the add-time and edit-time forms offers.
 *
 * Selectable = every publicly visible competition row (`IsCompetitionPubliclyVisible::SQL_CONDITION`):
 * approved & not-rejected standalone competitions regardless of their date (live, past, upcoming,
 * undated) plus every edition whose SERIES is approved & not rejected. The series umbrella itself is
 * not a row here (it is not a competition). Optionally one more id is always included — the edit form
 * passes the competition the time is currently linked to, so an unapproved/rejected link is not
 * silently dropped on re-save.
 *
 * Global order: live → undated standalone ("perpetual" online umbrellas, the most-used entries)
 * → past (newest first) → upcoming (soonest first) → undated editions. Undated editions that already
 * have rounds are dated by their first round instead, otherwise they would all float to the top.
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
    public function all(null|string $alwaysIncludeCompetitionId = null): array
    {
        if ($alwaysIncludeCompetitionId !== null && Uuid::isValid($alwaysIncludeCompetitionId) === false) {
            $alwaysIncludeCompetitionId = null;
        }

        $visibilityCondition = IsCompetitionPubliclyVisible::SQL_CONDITION;

        $query = <<<SQL
SELECT * FROM (
    SELECT c.id,
        c.name,
        c.shortcut,
        COALESCE(c.logo, cs.logo) AS logo,
        cs.logo AS series_logo,
        COALESCE(c.location, cs.location) AS location,
        COALESCE(c.location_country_code, cs.location_country_code) AS location_country_code,
        c.date_from,
        c.date_to,
        c.is_online,
        c.series_id,
        cs.name AS series_name,
        cs.shortcut AS series_shortcut,
        COALESCE(c.date_from, c.date_to, r.first_round_at) AS effective_from,
        CASE
            WHEN COALESCE(c.date_from, c.date_to, r.first_round_at) IS NULL THEN 'undated'
            WHEN :today::date BETWEEN COALESCE(c.date_from, c.date_to, r.first_round_at)::date
                AND COALESCE(c.date_to, c.date_from, r.first_round_at)::date THEN 'live'
            WHEN COALESCE(c.date_from, c.date_to, r.first_round_at)::date > :today::date THEN 'upcoming'
            ELSE 'past'
        END AS event_status
    FROM competition c
    LEFT JOIN competition_series cs ON cs.id = c.series_id
    LEFT JOIN LATERAL (
        SELECT MIN(starts_at) AS first_round_at
        FROM competition_round
        WHERE competition_id = c.id
    ) r ON true
    WHERE ({$visibilityCondition})
        OR c.id = :alwaysIncludeId
) s
ORDER BY
    CASE
        WHEN s.event_status = 'live' THEN 1
        WHEN s.event_status = 'undated' AND s.series_id IS NULL THEN 2
        WHEN s.event_status = 'past' THEN 3
        WHEN s.event_status = 'upcoming' THEN 4
        ELSE 5
    END,
    CASE WHEN s.event_status = 'past' THEN s.effective_from END DESC NULLS LAST,
    CASE WHEN s.event_status IN ('live', 'upcoming') THEN s.effective_from END ASC NULLS LAST,
    s.name ASC,
    s.id ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'today' => $this->clock->now()->format('Y-m-d'),
                'alwaysIncludeId' => $alwaysIncludeCompetitionId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): SelectableCompetition {
            /** @var SelectableCompetitionDatabaseRow $row */
            return SelectableCompetition::fromDatabaseRow($row);
        }, $data);
    }
}
