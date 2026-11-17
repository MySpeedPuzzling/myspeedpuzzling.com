<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * The day of a series' edition right before a given day - "Duplicate" proposes the copy's date with the same gap
 * (docs/features/competitions-management/README.md "Duplicating an event"). Organiser tooling: every edition counts,
 * a draft or one waiting for approval too - they are the organiser's own dates. An edition is dated by its date_from,
 * else by its first round's start (UTC day - good enough for a proposal the organiser sees and can change).
 */
readonly final class GetPreviousEditionDay
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function before(string $seriesId, string $exceptCompetitionId, DateTimeImmutable $day): null|DateTimeImmutable
    {
        if (Uuid::isValid($seriesId) === false || Uuid::isValid($exceptCompetitionId) === false) {
            return null;
        }

        $previous = $this->database->fetchOne(
            <<<SQL
SELECT MAX(edition_day)
FROM (
    SELECT COALESCE(c.date_from, (SELECT MIN(cr.starts_at) FROM competition_round cr WHERE cr.competition_id = c.id))::date AS edition_day
    FROM competition c
    WHERE c.series_id = :seriesId AND c.id <> :exceptId
) editions
WHERE edition_day < :day
SQL,
            [
                'seriesId' => $seriesId,
                'exceptId' => $exceptCompetitionId,
                'day' => $day->format('Y-m-d'),
            ],
        );

        if (is_string($previous) === false || $previous === '') {
            return null;
        }

        return new DateTimeImmutable($previous, new DateTimeZone('UTC'));
    }
}
