<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;
use SpeedPuzzling\Web\Results\CompetitionSeriesOverview;
use SpeedPuzzling\Web\Results\SeriesEdition;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class GetCompetitionSeries
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionSeriesNotFound
     */
    public function byId(string $seriesId): CompetitionSeriesOverview
    {
        $query = <<<SQL
SELECT id, name, slug, logo, description, link, is_online, location, location_country_code, added_by_player_id
FROM competition_series
WHERE id = :seriesId
SQL;

        $row = $this->database
            ->executeQuery($query, ['seriesId' => $seriesId])
            ->fetchAssociative();

        if ($row === false) {
            throw new CompetitionSeriesNotFound();
        }

        return $this->mapRow($row);
    }

    /**
     * @throws CompetitionSeriesNotFound
     */
    public function bySlug(string $slug): CompetitionSeriesOverview
    {
        $query = <<<SQL
SELECT id, name, slug, logo, description, link, is_online, location, location_country_code, added_by_player_id
FROM competition_series
WHERE slug = :slug
SQL;

        $row = $this->database
            ->executeQuery($query, ['slug' => $slug])
            ->fetchAssociative();

        if ($row === false) {
            throw new CompetitionSeriesNotFound();
        }

        return $this->mapRow($row);
    }

    /**
     * @return array<CompetitionSeriesOverview>
     */
    public function allApproved(): array
    {
        $query = <<<SQL
SELECT id, name, slug, logo, description, link, is_online, location, location_country_code, added_by_player_id
FROM competition_series
WHERE approved_at IS NOT NULL
ORDER BY name
SQL;

        $rows = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return array_map($this->mapRow(...), $rows);
    }

    /**
     * @return array<CompetitionSeriesOverview>
     */
    public function allUnapproved(): array
    {
        $query = <<<SQL
SELECT id, name, slug, logo, description, link, is_online, location, location_country_code, added_by_player_id
FROM competition_series
WHERE approved_at IS NULL AND rejected_at IS NULL
ORDER BY created_at DESC NULLS LAST
SQL;

        $rows = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return array_map($this->mapRow(...), $rows);
    }

    /**
     * @return array<CompetitionSeriesOverview>
     */
    public function allForPlayer(string $playerId): array
    {
        $query = <<<SQL
SELECT cs.id, cs.name, cs.slug, cs.logo, cs.description, cs.link, cs.is_online, cs.location, cs.location_country_code, cs.added_by_player_id
FROM competition_series cs
WHERE cs.added_by_player_id = :playerId
    OR cs.id IN (SELECT competition_series_id FROM competition_series_maintainer WHERE player_id = :playerId)
ORDER BY cs.created_at DESC NULLS LAST
SQL;

        $rows = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchAllAssociative();

        return array_map($this->mapRow(...), $rows);
    }

    /**
     * @return array<SeriesEdition>
     */
    public function upcomingEditions(string $seriesId): array
    {
        return $this->fetchEditions($seriesId, upcoming: true);
    }

    /**
     * @return array<SeriesEdition>
     */
    public function pastEditions(string $seriesId): array
    {
        return $this->fetchEditions($seriesId, upcoming: false);
    }

    /**
     * @return array<SeriesEdition>
     */
    private function fetchEditions(string $seriesId, bool $upcoming): array
    {
        $comparison = $upcoming ? '>=' : '<';
        $order = $upcoming ? 'ASC' : 'DESC';

        $query = <<<SQL
SELECT
    c.id AS competition_id,
    cr.id AS round_id,
    c.name,
    cr.starts_at,
    cr.minutes_limit,
    c.registration_link,
    c.results_link,
    COUNT(DISTINCT crp.id) AS puzzle_count,
    COUNT(DISTINCT cp.id) AS participant_count
FROM competition c
INNER JOIN competition_round cr ON cr.competition_id = c.id
LEFT JOIN competition_round_puzzle crp ON crp.round_id = cr.id
LEFT JOIN competition_participant cp ON cp.competition_id = c.id
WHERE c.series_id = :seriesId
    AND cr.starts_at {$comparison} :now
GROUP BY c.id, cr.id
ORDER BY cr.starts_at {$order}
SQL;

        $now = $this->clock->now();

        $rows = $this->database
            ->executeQuery($query, [
                'seriesId' => $seriesId,
                'now' => $now->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): SeriesEdition {
            /**
             * @var array{
             *     competition_id: string,
             *     round_id: string,
             *     name: string,
             *     starts_at: string,
             *     minutes_limit: int|string,
             *     puzzle_count: int|string,
             *     participant_count: int|string,
             *     registration_link: null|string,
             *     results_link: null|string,
             * } $row
             */
            return new SeriesEdition(
                competitionId: $row['competition_id'],
                roundId: $row['round_id'],
                name: $row['name'],
                startsAt: new DateTimeImmutable($row['starts_at']),
                minutesLimit: (int) $row['minutes_limit'],
                puzzleCount: (int) $row['puzzle_count'],
                participantCount: (int) $row['participant_count'],
                registrationLink: $row['registration_link'],
                resultsLink: $row['results_link'],
            );
        }, $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): CompetitionSeriesOverview
    {
        /**
         * @var array{
         *     id: string,
         *     name: string,
         *     slug: null|string,
         *     logo: null|string,
         *     description: null|string,
         *     link: null|string,
         *     is_online: bool|string,
         *     location: null|string,
         *     location_country_code: null|string,
         *     added_by_player_id: null|string,
         * } $row
         */

        $isOnline = $row['is_online'];
        if (is_string($isOnline)) {
            $isOnline = $isOnline === 't' || $isOnline === '1' || $isOnline === 'true';
        }

        return new CompetitionSeriesOverview(
            id: $row['id'],
            name: $row['name'],
            slug: $row['slug'],
            logo: $row['logo'],
            description: $row['description'],
            link: $row['link'],
            isOnline: $isOnline,
            location: $row['location'],
            locationCountryCode: $row['location_country_code'] !== null ? CountryCode::fromCode($row['location_country_code']) : null,
            addedByPlayerId: $row['added_by_player_id'],
        );
    }
}
