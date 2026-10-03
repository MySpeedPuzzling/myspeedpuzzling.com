<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Value\CommunityScope;

/**
 * How many events are coming up in the world or one country - the Players page spotlight's events number
 * (docs/features/players-page/README.md). A tiny count over the competition table, no player identity.
 */
readonly final class GetUpcomingEventsCount
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Publicly visible events (standalone ones and editions of an approved series, IsCompetitionPubliclyVisible)
     * starting after today - the events page's "upcoming". A country counts the events held there; the world counts
     * every event, online ones included.
     */
    public function forScope(CommunityScope $scope): int
    {
        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;
        $parameters = [
            'today' => $this->clock->now()->format('Y-m-d'),
        ];
        $inScope = '';

        if ($scope->country !== null) {
            // Historic rows carry uppercase codes
            $inScope = 'AND LOWER(c.location_country_code) = :country';
            $parameters['country'] = $scope->country->name;
        }

        $query = <<<SQL
SELECT COUNT(*)
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE {$visible}
    AND c.date_from::date > CAST(:today AS date)
    {$inScope}
SQL;

        $count = $this->database->executeQuery($query, $parameters)->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }
}
