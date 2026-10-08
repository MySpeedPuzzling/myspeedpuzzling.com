<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\OrganizationChoice;
use SpeedPuzzling\Web\Results\OrganizationDetail;
use SpeedPuzzling\Web\Results\OrganizationDirectoryRow;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\OrganizationKind;

/**
 * Lists of organizations (docs/features/organizations/README.md): the public directory, the choices of the
 * "Organization" select and the admin approval queue - one statement each.
 */
readonly final class GetOrganizations
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Publicly visible organizations by name, with the counts of their publicly visible series and one-time events
     *
     * @return list<OrganizationDirectoryRow>
     */
    public function publicDirectory(): array
    {
        $visible = IsOrganizationPubliclyVisible::SQL_CONDITION;
        $seriesVisible = IsSeriesPubliclyVisible::SQL_CONDITION;
        $competitionVisible = IsCompetitionPubliclyVisible::SQL_CONDITION;

        $query = <<<SQL
SELECT o.id, o.name, o.short_name, o.slug, o.logo, o.kind, o.country_code, o.region,
    (SELECT COUNT(*) FROM competition_series cs WHERE cs.organization_id = o.id AND {$seriesVisible}) AS series_count,
    (SELECT COUNT(*) FROM competition c LEFT JOIN competition_series cs ON cs.id = c.series_id
        WHERE c.organization_id = o.id AND c.series_id IS NULL AND {$competitionVisible}) AS event_count
FROM organization o
WHERE {$visible}
ORDER BY LOWER(o.name), o.id
SQL;

        $rows = [];

        /** @var array<string, null|string|int|bool> $row */

        foreach ($this->database->executeQuery($query)->fetchAllAssociative() as $row) {
            $kind = self::string($row['kind']);

            $rows[] = new OrganizationDirectoryRow(
                id: (string) $row['id'],
                name: (string) $row['name'],
                shortName: self::string($row['short_name']),
                slug: (string) $row['slug'],
                logo: self::string($row['logo']),
                kind: $kind !== null ? OrganizationKind::tryFrom($kind) : null,
                countryCode: CountryCode::fromCode(self::string($row['country_code'])),
                region: self::string($row['region']),
                seriesCount: is_numeric($row['series_count']) ? (int) $row['series_count'] : 0,
                eventCount: is_numeric($row['event_count']) ? (int) $row['event_count'] : 0,
            );
        }

        return $rows;
    }

    /**
     * The organizations the player is on the team of (creator or maintainer), not rejected, by name
     *
     * @return list<OrganizationChoice>
     */
    public function choicesForPlayer(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            return [];
        }

        return $this->choices(
            'o.rejected_at IS NULL AND (o.added_by_player_id = :playerId OR EXISTS (SELECT 1 FROM organization_maintainer om WHERE om.organization_id = o.id AND om.player_id = :playerId))',
            ['playerId' => $playerId],
        );
    }

    /**
     * Admins choose among every organization that is not rejected
     *
     * @return list<OrganizationChoice>
     */
    public function allChoices(): array
    {
        return $this->choices('o.rejected_at IS NULL', []);
    }

    /**
     * The admin approval queue: waiting for approval and not a draft (a draft is submitted by publishing it), newest
     * first, with the creator's name. Admin tooling - no viewer filtering.
     *
     * @return list<OrganizationDetail>
     */
    public function allUnapproved(): array
    {
        $columns = GetOrganization::COLUMNS;

        $query = <<<SQL
SELECT {$columns}, p.name AS added_by_player_name
FROM organization o
LEFT JOIN player p ON p.id = o.added_by_player_id
WHERE o.approved_at IS NULL AND o.rejected_at IS NULL AND o.is_draft = false
ORDER BY o.created_at DESC, o.id
SQL;

        return array_map(
            GetOrganization::hydrate(...),
            $this->database->executeQuery($query)->fetchAllAssociative(),
        );
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return list<OrganizationChoice>
     */
    private function choices(string $where, array $parameters): array
    {
        $query = <<<SQL
SELECT o.id, o.name, o.is_draft, (o.approved_at IS NOT NULL) AS is_approved
FROM organization o
WHERE {$where}
ORDER BY LOWER(o.name), o.id
SQL;

        $choices = [];

        /** @var array<string, null|string|int|bool> $row */

        foreach ($this->database->executeQuery($query, $parameters)->fetchAllAssociative() as $row) {
            $choices[] = new OrganizationChoice(
                id: (string) $row['id'],
                name: (string) $row['name'],
                isDraft: (bool) $row['is_draft'],
                isApproved: (bool) $row['is_approved'],
            );
        }

        return $choices;
    }

    private static function string(mixed $value): null|string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
