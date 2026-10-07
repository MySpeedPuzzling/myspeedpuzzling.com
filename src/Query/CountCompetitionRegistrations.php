<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CompetitionRegistrationCounts;
use SpeedPuzzling\Web\Value\RegistrationStatus;

/**
 * How full an event with managed registration is (docs/features/competitions-management/registration.md): the rows
 * holding a spot (the "going" rule - reserved, paid, and rows without a status) and the waitlist. Read inside the
 * participants lock by the registration itself, and by the organiser's settings page.
 */
readonly final class CountCompetitionRegistrations
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function of(string $competitionId): CompetitionRegistrationCounts
    {
        $going = CompetitionParticipantGoing::sql('cp');
        $waitlisted = RegistrationStatus::Waitlisted->value;

        $query = <<<SQL
SELECT
    COUNT(*) FILTER (WHERE {$going}) AS spots_taken,
    COUNT(*) FILTER (WHERE cp.deleted_at IS NULL AND cp.registration_status = '{$waitlisted}') AS waitlisted
FROM competition_participant cp
WHERE cp.competition_id = :competitionId
SQL;

        /** @var array{spots_taken: int|string, waitlisted: int|string} $row */
        $row = $this->database
            ->executeQuery($query, ['competitionId' => $competitionId])
            ->fetchAssociative();

        return new CompetitionRegistrationCounts(
            spotsTaken: (int) $row['spots_taken'],
            waitlisted: (int) $row['waitlisted'],
        );
    }
}
