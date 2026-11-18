<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * The events whose participants still have an organiser's e-mail address and whose last day may have ended more than
 * `$days` days ago (participant-linking.md D15) - a rough cut in SQL (a day of margin for every zone), the exact one in
 * the event's zone is EraseParticipantEmailsHandler's. Events without dates keep the addresses (nothing to count from).
 */
readonly final class GetEventsWithParticipantEmails
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<array{id: string, dateFrom: null|DateTimeImmutable, dateTo: null|DateTimeImmutable, registrationTimezone: null|string, countryCode: null|string, seriesCountryCode: null|string}>
     */
    public function endedBefore(int $days): array
    {
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT c.id, c.date_from, c.date_to, c.registration_timezone, c.location_country_code, cs.location_country_code AS series_country_code
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE COALESCE(c.date_to, c.date_from) IS NOT NULL
    AND CAST(COALESCE(c.date_to, c.date_from) AS date) < CAST(CAST(:now AS timestamp) AS date) - CAST(:days AS integer)
    AND EXISTS (SELECT 1 FROM competition_participant cp WHERE cp.competition_id = c.id AND cp.email IS NOT NULL)
ORDER BY c.id
SQL,
            ['now' => $this->clock->now()->format('Y-m-d H:i:s'), 'days' => $days - 1],
        );

        $events = [];

        foreach ($rows as $row) {
            /** @var array{id: string, date_from: null|string, date_to: null|string, registration_timezone: null|string, location_country_code: null|string, series_country_code: null|string} $row */
            $events[] = [
                'id' => $row['id'],
                'dateFrom' => $row['date_from'] !== null ? new DateTimeImmutable($row['date_from']) : null,
                'dateTo' => $row['date_to'] !== null ? new DateTimeImmutable($row['date_to']) : null,
                'registrationTimezone' => $row['registration_timezone'],
                'countryCode' => $row['location_country_code'],
                'seriesCountryCode' => $row['series_country_code'],
            ];
        }

        return $events;
    }
}
