<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;
use SpeedPuzzling\Web\Results\CompetitionSeriesOverview;
use SpeedPuzzling\Web\Results\SeriesEdition;
use SpeedPuzzling\Web\Value\RoundTimezone;
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
        $shownOnPage = GetCompetitionPageSections::sqlShownOnSeriesPage('s', 'cs');
        $query = <<<SQL
SELECT cs.id, cs.name, cs.slug, cs.logo, cs.description, cs.link, cs.is_online, cs.location, cs.location_country_code, cs.added_by_player_id, cs.approved_at, cs.rejected_at,
       EXISTS (SELECT 1 FROM competition_page_section s WHERE {$shownOnPage}) AS has_page_sections
FROM competition_series cs
WHERE cs.id = :seriesId
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
        $shownOnPage = GetCompetitionPageSections::sqlShownOnSeriesPage('s', 'cs');
        $query = <<<SQL
SELECT cs.id, cs.name, cs.slug, cs.logo, cs.description, cs.link, cs.is_online, cs.location, cs.location_country_code, cs.added_by_player_id, cs.approved_at, cs.rejected_at,
       EXISTS (SELECT 1 FROM competition_page_section s WHERE {$shownOnPage}) AS has_page_sections
FROM competition_series cs
WHERE cs.slug = :slug
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
     * The admin approval queue.
     *
     * @return array<CompetitionSeriesOverview>
     */
    public function allUnapproved(): array
    {
        $query = <<<SQL
SELECT cs.id, cs.name, cs.slug, cs.logo, cs.description, cs.link, cs.is_online, cs.location, cs.location_country_code, cs.added_by_player_id, cs.approved_at, cs.rejected_at,
    p.name AS added_by_player_name
FROM competition_series cs
LEFT JOIN player p ON p.id = cs.added_by_player_id
WHERE cs.approved_at IS NULL AND cs.rejected_at IS NULL
ORDER BY cs.created_at DESC NULLS LAST
SQL;

        $rows = $this->database
            ->executeQuery($query)
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
     * An edition is dated by its first round, else by its own date_from. One with neither (no date and no
     * rounds yet - a draft, or a duplicate) is listed with the upcoming ones, last: it must never vanish
     * from the series page nor from the organiser's management page.
     *
     * @return array<SeriesEdition>
     */
    private function fetchEditions(string $seriesId, bool $upcoming): array
    {
        $editionStart = 'COALESCE((SELECT MIN(cr2.starts_at) FROM competition_round cr2 WHERE cr2.competition_id = c.id), c.date_from)';
        $dateCondition = $upcoming
            ? "({$editionStart} >= :now OR {$editionStart} IS NULL)"
            : "{$editionStart} < :now";
        $order = $upcoming ? 'ASC' : 'DESC';
        $going = CompetitionParticipantGoing::sql('cp');

        $query = <<<SQL
SELECT
    c.id AS competition_id,
    c.name,
    c.slug,
    c.logo,
    c.date_from,
    c.date_to,
    -- Hidden while the edition manages registration on MySpeedPuzzling (docs/features/competitions-management/registration.md)
    CASE WHEN c.registration_managed THEN NULL ELSE c.registration_link END AS registration_link,
    c.results_link,
    MIN(cr.starts_at) AS starts_at,
    -- An edition's rounds share one zone in practice; any of them shows its first start right
    MIN(cr.timezone) AS timezone,
    c.location_country_code,
    (SELECT tz_cs.location_country_code FROM competition_series tz_cs WHERE tz_cs.id = c.series_id) AS series_country_code,
    MIN(cr.minutes_limit) AS minutes_limit,
    COUNT(DISTINCT cr.id) AS round_count,
    COUNT(DISTINCT crp.id) AS puzzle_count,
    COUNT(DISTINCT cp.id) AS participant_count
FROM competition c
LEFT JOIN competition_round cr ON cr.competition_id = c.id
LEFT JOIN competition_round_puzzle crp ON crp.round_id = cr.id
LEFT JOIN competition_participant cp ON cp.competition_id = c.id AND {$going}
WHERE c.series_id = :seriesId
    AND {$dateCondition}
GROUP BY c.id
ORDER BY COALESCE(MIN(cr.starts_at), c.date_from) {$order} NULLS LAST, c.created_at, c.id
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
             *     name: string,
             *     slug: null|string,
             *     logo: null|string,
             *     date_from: null|string,
             *     date_to: null|string,
             *     starts_at: null|string,
             *     timezone: null|string,
             *     location_country_code: null|string,
             *     series_country_code: null|string,
             *     minutes_limit: null|int|string,
             *     round_count: int|string,
             *     puzzle_count: int|string,
             *     participant_count: int|string,
             *     registration_link: null|string,
             *     results_link: null|string,
             * } $row
             */
            return new SeriesEdition(
                competitionId: $row['competition_id'],
                name: $row['name'],
                editionSlug: $row['slug'],
                startsAt: $row['starts_at'] !== null ? new DateTimeImmutable($row['starts_at']) : null,
                minutesLimit: $row['minutes_limit'] !== null ? (int) $row['minutes_limit'] : null,
                roundCount: (int) $row['round_count'],
                puzzleCount: (int) $row['puzzle_count'],
                participantCount: (int) $row['participant_count'],
                registrationLink: $row['registration_link'],
                resultsLink: $row['results_link'],
                timezone: RoundTimezone::resolve($row['timezone'], $row['location_country_code'], $row['series_country_code']),
                logo: $row['logo'],
                dateFrom: $row['date_from'] !== null ? new DateTimeImmutable($row['date_from']) : null,
                dateTo: $row['date_to'] !== null ? new DateTimeImmutable($row['date_to']) : null,
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
         *     approved_at: null|string,
         *     rejected_at: null|string,
         *     added_by_player_name?: null|string,
         *     has_page_sections?: bool,
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
            approvedAt: $row['approved_at'] !== null ? new DateTimeImmutable($row['approved_at']) : null,
            rejectedAt: $row['rejected_at'] !== null ? new DateTimeImmutable($row['rejected_at']) : null,
            addedByPlayerName: $row['added_by_player_name'] ?? null,
            hasPageSections: $row['has_page_sections'] ?? false,
        );
    }
}
