<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Value\OccurrenceRound;
use SpeedPuzzling\Web\Value\RoundTimezone;

/**
 * The rounds an occurrence is dated by (OccurrenceDates::sessions()), shared by every statement that dates
 * occurrences - the events page (GetEventOccurrences), the archive years of the sitemap and "You organize". One LEFT
 * JOIN aliased `r` on `c` (competition): `r.rounds` (a JSON list in start order, NULL without rounds) and
 * `r.round_count`.
 */
final class OccurrenceRounds
{
    public const string SQL_JOIN = <<<SQL
LEFT JOIN (
    SELECT competition_id,
        json_agg(json_build_object('id', id, 'name', name, 'starts_at', starts_at, 'timezone', timezone) ORDER BY starts_at, id) AS rounds,
        COUNT(*) AS round_count
    FROM competition_round
    GROUP BY competition_id
) r ON r.competition_id = c.id
SQL;

    /**
     * @param null|string ...$countryCodes the event's own, then its series' country - RoundTimezone::resolve()
     *
     * @return list<OccurrenceRound>
     */
    public static function fromJson(mixed $json, null|string ...$countryCodes): array
    {
        if (is_string($json) === false || $json === '') {
            return [];
        }

        $items = json_decode($json, true);

        if (is_array($items) === false) {
            return [];
        }

        $rounds = [];

        foreach ($items as $item) {
            if (is_array($item) === false || is_string($item['starts_at'] ?? null) === false) {
                continue;
            }

            $zone = $item['timezone'] ?? null;

            $rounds[] = new OccurrenceRound(
                id: is_string($item['id'] ?? null) ? $item['id'] : '',
                name: is_string($item['name'] ?? null) ? $item['name'] : '',
                startsAt: new DateTimeImmutable($item['starts_at'], new DateTimeZone('UTC')),
                zone: RoundTimezone::resolve(is_string($zone) ? $zone : null, ...$countryCodes),
            );
        }

        return $rounds;
    }
}
