<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\EventSeriesRow;
use SpeedPuzzling\Web\Results\OrganizationRef;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * The series of the events page's directory (docs/features/events-page/implementation-plan.md, 1.4). Edition counts,
 * next and last dates come from the occurrences (EventsPageBuilder). A series rejected after its approval is not
 * listed, a draft neither (IsSeriesPubliclyVisible) - admins also get the ones waiting for approval, never drafts.
 *
 * forOrganization() - the organization page's "What we run" cards (docs/features/organizations/README.md): its public
 * series, or for its team its drafts and pending ones too. Each row carries its organization.
 */
readonly final class GetEventSeriesDirectory
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<EventSeriesRow>
     */
    public function all(bool $includeUnapproved): array
    {
        $visible = IsSeriesPubliclyVisible::SQL_CONDITION;
        $approval = $includeUnapproved ? '' : "AND {$visible}";

        return $this->fetch("cs.rejected_at IS NULL AND cs.is_draft = false {$approval}", []);
    }

    /**
     * @return list<EventSeriesRow>
     */
    public function forOrganization(string $organizationId, bool $includeDrafts = false): array
    {
        if (Uuid::isValid($organizationId) === false) {
            return [];
        }

        $visible = IsSeriesPubliclyVisible::SQL_CONDITION;
        $condition = $includeDrafts ? '' : "AND {$visible}";

        return $this->fetch("cs.organization_id = :organizationId AND cs.rejected_at IS NULL {$condition}", [
            'organizationId' => $organizationId,
        ]);
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return list<EventSeriesRow>
     */
    private function fetch(string $where, array $parameters): array
    {
        $visible = IsSeriesPubliclyVisible::SQL_CONDITION;
        $organizationVisible = IsOrganizationPubliclyVisible::SQL_CONDITION;

        $query = <<<SQL
SELECT cs.id, cs.name, cs.slug, cs.is_online, cs.location, cs.location_country_code,
    ({$visible}) AS is_public,
    cs.eligibility, cs.schedule, cs.is_draft,
    o.id AS organization_id,
    o.name AS organization_name,
    o.short_name AS organization_short_name,
    o.slug AS organization_slug,
    COALESCE(({$organizationVisible}), false) AS organization_public
FROM competition_series cs
LEFT JOIN organization o ON o.id = cs.organization_id
WHERE {$where}
ORDER BY cs.name
SQL;

        $rows = [];

        /** @var array<string, null|string|int|bool> $row */

        foreach ($this->database->executeQuery($query, $parameters)->fetchAllAssociative() as $row) {
            $rows[] = new EventSeriesRow(
                id: (string) $row['id'],
                name: (string) $row['name'],
                slug: self::nullableString($row['slug']),
                isOnline: (bool) $row['is_online'],
                location: self::nullableString($row['location']),
                countryCode: CountryCode::fromCode(is_string($row['location_country_code']) ? $row['location_country_code'] : null),
                isPublic: (bool) $row['is_public'],
                organization: OrganizationRef::fromRow($row),
                eligibility: self::nullableString($row['eligibility']),
                schedule: self::nullableString($row['schedule']),
                isDraft: (bool) $row['is_draft'],
            );
        }

        return $rows;
    }

    private static function nullableString(mixed $value): null|string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
