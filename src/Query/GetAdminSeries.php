<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;
use SpeedPuzzling\Web\Results\AdminCompetitionMaintainer;
use SpeedPuzzling\Web\Results\AdminSeries;
use SpeedPuzzling\Web\Results\AdminSeriesDetail;

/**
 * Series as the internal API shows them to an admin: every series - approved, pending, rejected or a draft - with
 * everything the API can edit, and its editions (docs/features/internal-api.md "Organizations, series and drafts").
 */
readonly final class GetAdminSeries
{
    private const string COLUMNS = <<<SQL
cs.id,
cs.name,
cs.slug,
cs.shortcut,
cs.description,
cs.link,
cs.is_online,
cs.location,
cs.location_country_code,
cs.logo,
cs.organization_id,
o.name AS organization_name,
o.slug AS organization_slug,
cs.eligibility,
cs.schedule,
cs.is_draft,
cs.approved_at,
cs.approved_by_player_id,
cs.rejected_at,
cs.rejection_reason,
cs.created_at,
cs.added_by_player_id,
added_by.name AS added_by_player_name,
(SELECT COUNT(*) FROM competition e WHERE e.series_id = cs.id) AS editions_count
SQL;

    private const string JOINS = <<<SQL
LEFT JOIN organization o ON o.id = cs.organization_id
LEFT JOIN player added_by ON added_by.id = cs.added_by_player_id
SQL;

    public function __construct(
        private Connection $database,
        private GetAdminCompetitions $getAdminCompetitions,
    ) {
    }

    /**
     * By name. `$search` matches the name, slug or shortcut, accents and letter case ignored.
     *
     * @return list<AdminSeries>
     */
    public function search(null|string $search, null|string $status, int $limit, int $offset): array
    {
        [$where, $params] = self::filter($search, $status);
        $columns = self::COLUMNS;
        $joins = self::JOINS;
        $visible = IsSeriesPubliclyVisible::SQL_CONDITION;

        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT {$columns}, ({$visible}) AS publicly_visible
FROM competition_series cs
{$joins}
WHERE {$where}
ORDER BY cs.name, cs.id
LIMIT :limit OFFSET :offset
SQL, [...$params, 'limit' => $limit, 'offset' => $offset]);

        return array_map(self::series(...), $rows);
    }

    public function count(null|string $search, null|string $status): int
    {
        [$where, $params] = self::filter($search, $status);

        $count = $this->database->fetchOne(<<<SQL
SELECT COUNT(*)
FROM competition_series cs
WHERE {$where}
SQL, $params);

        return is_numeric($count) ? (int) $count : 0;
    }

    public function exists(string $seriesId): bool
    {
        return Uuid::isValid($seriesId)
            && $this->database->fetchOne('SELECT 1 FROM competition_series WHERE id = :id', ['id' => $seriesId]) !== false;
    }

    /**
     * By id, or by slug (unique among series).
     *
     * @throws CompetitionSeriesNotFound
     */
    public function detail(string $idOrSlug): AdminSeriesDetail
    {
        $series = $this->one(Uuid::isValid($idOrSlug) ? 'cs.id = :value' : 'cs.slug = :value', Uuid::isValid($idOrSlug) ? strtolower($idOrSlug) : $idOrSlug);

        return new AdminSeriesDetail(
            series: $series,
            maintainers: $this->maintainers($series->seriesId),
            editions: $this->getAdminCompetitions->ofSeries($series->seriesId),
        );
    }

    /**
     * The series of an organization, by name
     *
     * @return list<AdminSeries>
     */
    public function ofOrganization(string $organizationId): array
    {
        if (Uuid::isValid($organizationId) === false) {
            return [];
        }

        $columns = self::COLUMNS;
        $joins = self::JOINS;
        $visible = IsSeriesPubliclyVisible::SQL_CONDITION;

        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT {$columns}, ({$visible}) AS publicly_visible
FROM competition_series cs
{$joins}
WHERE cs.organization_id = :organizationId
ORDER BY cs.name, cs.id
SQL, ['organizationId' => $organizationId]);

        return array_map(self::series(...), $rows);
    }

    /**
     * @throws CompetitionSeriesNotFound
     */
    private function one(string $condition, string $value): AdminSeries
    {
        $columns = self::COLUMNS;
        $joins = self::JOINS;
        $visible = IsSeriesPubliclyVisible::SQL_CONDITION;

        $row = $this->database->fetchAssociative(<<<SQL
SELECT {$columns}, ({$visible}) AS publicly_visible
FROM competition_series cs
{$joins}
WHERE {$condition}
SQL, ['value' => $value]);

        if ($row === false) {
            throw new CompetitionSeriesNotFound();
        }

        return self::series($row);
    }

    /**
     * @return list<AdminCompetitionMaintainer>
     */
    private function maintainers(string $seriesId): array
    {
        /** @var list<array{id: string, name: null|string, code: string}> $rows */
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT p.id, p.name, p.code
FROM competition_series_maintainer csm
INNER JOIN player p ON p.id = csm.player_id
WHERE csm.competition_series_id = :seriesId
ORDER BY p.name, p.code
SQL, ['seriesId' => $seriesId]);

        return array_map(
            static fn (array $row): AdminCompetitionMaintainer => new AdminCompetitionMaintainer($row['id'], $row['name'], $row['code']),
            $rows,
        );
    }

    /**
     * @return array{string, array<string, string>}
     */
    private static function filter(null|string $search, null|string $status): array
    {
        $conditions = ['TRUE'];
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $conditions[] = <<<SQL
(
    immutable_unaccent(cs.name) ILIKE immutable_unaccent(:pattern)
    OR cs.slug ILIKE :pattern
    OR cs.shortcut ILIKE :pattern
)
SQL;
            $params['pattern'] = '%' . addcslashes(trim($search), '%_\\') . '%';
        }

        // The approval state, drafts aside: an approved draft is approved (and listed under `draft` too)
        $conditions[] = match ($status) {
            'approved' => '(cs.approved_at IS NOT NULL AND cs.rejected_at IS NULL)',
            'pending' => '(cs.approved_at IS NULL AND cs.rejected_at IS NULL)',
            'rejected' => 'cs.rejected_at IS NOT NULL',
            'draft' => 'cs.is_draft = true',
            default => 'TRUE',
        };

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function series(array $row): AdminSeries
    {
        /**
         * @var array{
         *     id: string,
         *     name: string,
         *     slug: null|string,
         *     shortcut: null|string,
         *     description: null|string,
         *     link: null|string,
         *     is_online: bool,
         *     location: null|string,
         *     location_country_code: null|string,
         *     logo: null|string,
         *     organization_id: null|string,
         *     organization_name: null|string,
         *     organization_slug: null|string,
         *     eligibility: null|string,
         *     schedule: null|string,
         *     is_draft: bool,
         *     approved_at: null|string,
         *     approved_by_player_id: null|string,
         *     rejected_at: null|string,
         *     rejection_reason: null|string,
         *     publicly_visible: bool,
         *     created_at: null|string,
         *     added_by_player_id: null|string,
         *     added_by_player_name: null|string,
         *     editions_count: int,
         * } $row
         */
        return AdminSeries::fromDatabaseRow($row);
    }
}
