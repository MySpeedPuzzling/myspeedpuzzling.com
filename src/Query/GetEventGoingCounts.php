<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\EventsPage\EventCounts;

/**
 * "41 going" on the events page - spots taken by the going rule (CompetitionParticipantGoing), counts only - and, in the
 * same statement, "6 looking for a partner": the open partner listings (docs/features/partner-finder.md), counted on
 * the partial index custom_partner_listing_open_by_event. Aggregates without identity: no blocklist, no privacy rule.
 */
readonly final class GetEventGoingCounts
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $competitionIds
     */
    public function forCompetitions(array $competitionIds): EventCounts
    {
        if ($competitionIds === []) {
            return new EventCounts();
        }

        $going = CompetitionParticipantGoing::sql('cp');

        // Two grouped counts joined on the competition: the going count keeps its own plan, the listings are read from
        // the partial index of open listings only
        $query = <<<SQL
SELECT competition_id,
    COALESCE(going.spots_taken, 0) AS spots_taken,
    COALESCE(listings.listing_count, 0) AS listing_count
FROM (
    SELECT cp.competition_id, COUNT(*) AS spots_taken
    FROM competition_participant cp
    WHERE cp.competition_id IN (:ids) AND {$going}
    GROUP BY cp.competition_id
) going
FULL JOIN (
    SELECT pl.competition_id, COUNT(*) AS listing_count
    FROM partner_listing pl
    WHERE pl.competition_id IN (:ids) AND pl.closed_at IS NULL
    GROUP BY pl.competition_id
) listings USING (competition_id)
SQL;

        $going = [];
        $listings = [];

        $rows = $this->database->executeQuery(
            $query,
            ['ids' => array_values(array_unique(array_map(strtolower(...), $competitionIds)))],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        /** @var array<string, null|string|int|bool> $row */
        foreach ($rows as $row) {
            $competitionId = (string) $row['competition_id'];
            $spotsTaken = is_numeric($row['spots_taken']) ? (int) $row['spots_taken'] : 0;
            $listingCount = is_numeric($row['listing_count']) ? (int) $row['listing_count'] : 0;

            if ($spotsTaken > 0) {
                $going[$competitionId] = $spotsTaken;
            }

            if ($listingCount > 0) {
                $listings[$competitionId] = $listingCount;
            }
        }

        return new EventCounts($going, $listings);
    }
}
