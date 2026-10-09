<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\OrganizationNotFound;
use SpeedPuzzling\Web\Results\AdminCompetitionMaintainer;
use SpeedPuzzling\Web\Results\AdminOrganization;
use SpeedPuzzling\Web\Results\AdminOrganizationDetail;

/**
 * Organizations as the internal API shows them to an admin: every organization - approved, pending, rejected or a
 * draft - with everything the API can edit, its team, its series and its one-time events
 * (docs/features/internal-api.md "Organizations, series and drafts").
 */
readonly final class GetAdminOrganizations
{
    private const string COLUMNS = <<<SQL
o.id,
o.name,
o.short_name,
o.slug,
o.logo,
o.about,
o.website,
o.social_links,
o.country_code,
o.region,
o.kind,
o.is_draft,
o.approved_at,
o.approved_by_player_id,
o.rejected_at,
o.rejection_reason,
o.created_at,
o.added_by_player_id,
added_by.name AS added_by_player_name,
(SELECT COUNT(*) FROM competition_series o_cs WHERE o_cs.organization_id = o.id) AS series_count,
(SELECT COUNT(*) FROM competition o_c WHERE o_c.organization_id = o.id) AS events_count
SQL;

    public function __construct(
        private Connection $database,
        private GetAdminSeries $getAdminSeries,
        private GetAdminCompetitions $getAdminCompetitions,
    ) {
    }

    /**
     * By name. `$search` matches the name, short name or slug, accents and letter case ignored.
     *
     * @return list<AdminOrganization>
     */
    public function search(null|string $search, null|string $status, int $limit, int $offset): array
    {
        [$where, $params] = self::filter($search, $status);
        $columns = self::COLUMNS;
        $visible = IsOrganizationPubliclyVisible::SQL_CONDITION;

        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT {$columns}, ({$visible}) AS publicly_visible
FROM organization o
LEFT JOIN player added_by ON added_by.id = o.added_by_player_id
WHERE {$where}
ORDER BY o.name, o.id
LIMIT :limit OFFSET :offset
SQL, [...$params, 'limit' => $limit, 'offset' => $offset]);

        return array_map(self::organization(...), $rows);
    }

    public function count(null|string $search, null|string $status): int
    {
        [$where, $params] = self::filter($search, $status);

        $count = $this->database->fetchOne("SELECT COUNT(*) FROM organization o WHERE {$where}", $params);

        return is_numeric($count) ? (int) $count : 0;
    }

    public function exists(string $organizationId): bool
    {
        return Uuid::isValid($organizationId)
            && $this->database->fetchOne('SELECT 1 FROM organization WHERE id = :id', ['id' => $organizationId]) !== false;
    }

    /**
     * By id, or by slug (unique among organizations).
     *
     * @throws OrganizationNotFound
     */
    public function detail(string $idOrSlug): AdminOrganizationDetail
    {
        $columns = self::COLUMNS;
        $visible = IsOrganizationPubliclyVisible::SQL_CONDITION;
        $isId = Uuid::isValid($idOrSlug);
        $condition = $isId ? 'o.id = :value' : 'o.slug = :value';

        $row = $this->database->fetchAssociative(<<<SQL
SELECT {$columns}, ({$visible}) AS publicly_visible
FROM organization o
LEFT JOIN player added_by ON added_by.id = o.added_by_player_id
WHERE {$condition}
SQL, ['value' => $isId ? strtolower($idOrSlug) : $idOrSlug]);

        if ($row === false) {
            throw new OrganizationNotFound();
        }

        $organization = self::organization($row);

        return new AdminOrganizationDetail(
            organization: $organization,
            maintainers: $this->maintainers($organization->organizationId),
            series: $this->getAdminSeries->ofOrganization($organization->organizationId),
            events: $this->getAdminCompetitions->oneTimeOfOrganization($organization->organizationId),
        );
    }

    /**
     * @return list<AdminCompetitionMaintainer>
     */
    private function maintainers(string $organizationId): array
    {
        /** @var list<array{id: string, name: null|string, code: string}> $rows */
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT p.id, p.name, p.code
FROM organization_maintainer om
INNER JOIN player p ON p.id = om.player_id
WHERE om.organization_id = :organizationId
ORDER BY p.name, p.code
SQL, ['organizationId' => $organizationId]);

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
    immutable_unaccent(o.name) ILIKE immutable_unaccent(:pattern)
    OR immutable_unaccent(COALESCE(o.short_name, '')) ILIKE immutable_unaccent(:pattern)
    OR o.slug ILIKE :pattern
)
SQL;
            $params['pattern'] = '%' . addcslashes(trim($search), '%_\\') . '%';
        }

        // The approval state, drafts aside: an approved draft is approved (and listed under `draft` too)
        $conditions[] = match ($status) {
            'approved' => '(o.approved_at IS NOT NULL AND o.rejected_at IS NULL)',
            'pending' => '(o.approved_at IS NULL AND o.rejected_at IS NULL)',
            'rejected' => 'o.rejected_at IS NOT NULL',
            'draft' => 'o.is_draft = true',
            default => 'TRUE',
        };

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function organization(array $row): AdminOrganization
    {
        /**
         * @var array{
         *     id: string,
         *     name: string,
         *     short_name: null|string,
         *     slug: string,
         *     logo: null|string,
         *     about: null|string,
         *     website: null|string,
         *     social_links: string,
         *     country_code: null|string,
         *     region: null|string,
         *     kind: null|string,
         *     is_draft: bool,
         *     approved_at: null|string,
         *     approved_by_player_id: null|string,
         *     rejected_at: null|string,
         *     rejection_reason: null|string,
         *     publicly_visible: bool,
         *     created_at: string,
         *     added_by_player_id: null|string,
         *     added_by_player_name: null|string,
         *     series_count: int,
         *     events_count: int,
         * } $row
         */
        return AdminOrganization::fromDatabaseRow($row);
    }
}
