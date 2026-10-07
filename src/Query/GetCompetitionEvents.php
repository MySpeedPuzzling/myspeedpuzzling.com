<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Results\CompetitionEvent;
use SpeedPuzzling\Web\Results\CompetitionReference;

/**
 * @phpstan-import-type CompetitionEventDatabaseRow from CompetitionEvent
 */
readonly final class GetCompetitionEvents
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function byId(string $competitionId): CompetitionEvent
    {
        if (Uuid::isValid($competitionId) === false) {
            throw new CompetitionNotFound();
        }

        // hasPageSections: one EXISTS in the statement every competition page runs anyway - a page without sections
        // never queries them (docs/features/competitions-management/public-page.md); hasPublishedOfficialResults the
        // same for the official results (docs/features/competitions-management/official-results.md)
        $shownOnPage = GetCompetitionPageSections::sqlShownOnCompetitionPage('s', 'c');
        $showsOfficialResults = GetPublishedRoundResults::sqlShowsOfficialResults('official_round');
        $query = <<<SQL
SELECT
    c.*,
    EXISTS (SELECT 1 FROM competition_page_section s WHERE {$shownOnPage}) AS has_page_sections,
    EXISTS (SELECT 1 FROM competition_round official_round WHERE official_round.competition_id = c.id AND {$showsOfficialResults}) AS has_published_official_results
FROM competition c
WHERE c.id = :id
SQL;

        /** @var false|CompetitionEventDatabaseRow $data */
        $data = $this->database
            ->executeQuery($query, [
                'id' => $competitionId,
            ])
            ->fetchAssociative();

        if ($data === false) {
            throw new CompetitionNotFound();
        }

        return CompetitionEvent::fromDatabaseRow($data);
    }

    /**
     * What a link to the competition's own page needs, whatever its kind or visibility: an edition is
     * reached through its series (CompetitionReference::routeName()).
     */
    public function referenceById(string $competitionId): CompetitionReference
    {
        if (Uuid::isValid($competitionId) === false) {
            throw new CompetitionNotFound();
        }

        $query = <<<SQL
SELECT c.name, c.slug, cs.name AS series_name, cs.slug AS series_slug
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE c.id = :id
SQL;

        /** @var false|array{name: string, slug: null|string, series_name: null|string, series_slug: null|string} $row */
        $row = $this->database
            ->executeQuery($query, [
                'id' => $competitionId,
            ])
            ->fetchAssociative();

        if ($row === false) {
            throw new CompetitionNotFound();
        }

        return new CompetitionReference(
            name: $row['name'],
            slug: $row['slug'],
            seriesName: $row['series_name'],
            seriesSlug: $row['series_slug'],
        );
    }

    /**
     * @return array<CompetitionEvent>
     */
    public function search(
        string $timePeriod = 'all',
        bool $onlineOnly = false,
        null|string $country = null,
    ): array {
        $date = $this->clock->now()->format('Y-m-d');
        $params = ['date' => $date];

        $cte = <<<'SQL'
        WITH event_classified AS (
            SELECT c.*,
                CASE
                    WHEN c.date_from IS NOT NULL
                        AND :date::date BETWEEN COALESCE(c.date_from, c.date_to)::date AND COALESCE(c.date_to, c.date_from)::date
                        THEN 'live'
                    WHEN COALESCE(c.date_from, c.date_to)::date > :date::date
                        THEN 'upcoming'
                    ELSE 'past'
                END AS event_status,
                COALESCE(c.date_from, c.date_to) AS sort_date
            FROM competition c
            WHERE c.approved_at IS NOT NULL
                AND c.rejected_at IS NULL
                AND c.series_id IS NULL
        )
        SQL;

        $whereClauses = [];

        if (in_array($timePeriod, ['live', 'upcoming', 'past'], true)) {
            $whereClauses[] = 'event_status = :status';
            $params['status'] = $timePeriod;
        }

        if ($onlineOnly) {
            $whereClauses[] = 'is_online = true';
        }

        if ($country !== null) {
            // Historic rows carry uppercase ISO codes while the UI submits lowercase.
            $whereClauses[] = 'LOWER(location_country_code) = LOWER(:country)';
            $params['country'] = $country;
        }

        $where = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        $orderBy = match ($timePeriod) {
            'live' => 'sort_date ASC NULLS LAST',
            'upcoming' => 'sort_date ASC NULLS LAST',
            'past' => 'sort_date DESC NULLS LAST',
            default => <<<'SQL'
            CASE event_status WHEN 'live' THEN 1 WHEN 'upcoming' THEN 2 WHEN 'past' THEN 3 END ASC,
            CASE WHEN event_status != 'past' THEN sort_date END ASC NULLS LAST,
            CASE WHEN event_status = 'past' THEN sort_date END DESC NULLS LAST
            SQL,
        };

        $query = "{$cte} SELECT * FROM event_classified {$where} ORDER BY {$orderBy}";

        $data = $this->database
            ->executeQuery($query, $params)
            ->fetchAllAssociative();

        return array_map(static function (array $row): CompetitionEvent {
            /** @var CompetitionEventDatabaseRow $row */
            return CompetitionEvent::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<CompetitionEvent>
     */
    public function allUnapproved(): array
    {
        $query = <<<SQL
SELECT c.*, p.name AS added_by_player_name
FROM competition c
LEFT JOIN player p ON p.id = c.added_by_player_id
WHERE c.approved_at IS NULL
    AND c.rejected_at IS NULL
    AND c.series_id IS NULL
ORDER BY c.created_at DESC;
SQL;

        $data = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return array_map(static function (array $row): CompetitionEvent {
            /** @var CompetitionEventDatabaseRow $row */
            return CompetitionEvent::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<CompetitionEvent>
     */
    public function allForPlayer(string $playerId): array
    {
        $query = <<<SQL
SELECT c.*
FROM competition c
WHERE c.series_id IS NULL
   AND (c.added_by_player_id = :playerId
       OR c.id IN (SELECT competition_id FROM competition_maintainer WHERE player_id = :playerId))
ORDER BY c.created_at DESC NULLS LAST, c.date_from DESC;
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): CompetitionEvent {
            /** @var CompetitionEventDatabaseRow $row */
            return CompetitionEvent::fromDatabaseRow($row);
        }, $data);
    }
}
