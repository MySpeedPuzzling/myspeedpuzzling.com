<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\EventSeriesRow;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * The series of the events page's directory (docs/features/events-page/implementation-plan.md, 1.4). Edition counts,
 * next and last dates come from the occurrences (EventsPageBuilder). A series rejected after its approval is not
 * listed - the IsCompetitionPubliclyVisible rule. Admins also get the ones waiting for approval.
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
        $approval = $includeUnapproved ? '' : 'AND cs.approved_at IS NOT NULL';

        $query = <<<SQL
SELECT cs.id, cs.name, cs.slug, cs.is_online, cs.location, cs.location_country_code,
    (cs.approved_at IS NOT NULL) AS is_public
FROM competition_series cs
WHERE cs.rejected_at IS NULL {$approval}
ORDER BY cs.name
SQL;

        $rows = [];

        /** @var array<string, null|string|int|bool> $row */

        foreach ($this->database->executeQuery($query)->fetchAllAssociative() as $row) {
            $rows[] = new EventSeriesRow(
                id: (string) $row['id'],
                name: (string) $row['name'],
                slug: is_string($row['slug']) && $row['slug'] !== '' ? $row['slug'] : null,
                isOnline: (bool) $row['is_online'],
                location: is_string($row['location']) && $row['location'] !== '' ? $row['location'] : null,
                countryCode: CountryCode::fromCode(is_string($row['location_country_code']) ? $row['location_country_code'] : null),
                isPublic: (bool) $row['is_public'],
            );
        }

        return $rows;
    }
}
