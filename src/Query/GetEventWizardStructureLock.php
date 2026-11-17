<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Whether the creation wizard's Structure step may still change an event's rounds (W-creation-wizard-research.md §5
 * "Re-entering Structure after people registered"): not once any round has an entry (a participant or a pair/team in
 * it), a result linked to it, or a stopwatch that ran - then the step is read-only and the rounds are changed on their
 * own pages. Organiser tooling - the caller checks COMPETITION_EDIT.
 */
readonly final class GetEventWizardStructureLock
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function isLocked(string $competitionId): bool
    {
        $query = <<<SQL
SELECT EXISTS (
    SELECT 1 FROM competition_participant_round cpr
    INNER JOIN competition_round cr ON cr.id = cpr.round_id
    WHERE cr.competition_id = :competitionId
) OR EXISTS (
    SELECT 1 FROM competition_team ct
    INNER JOIN competition_round cr ON cr.id = ct.round_id
    WHERE cr.competition_id = :competitionId
) OR EXISTS (
    SELECT 1 FROM puzzle_solving_time pst
    INNER JOIN competition_round cr ON cr.id = pst.competition_round_id
    WHERE cr.competition_id = :competitionId
) OR EXISTS (
    SELECT 1 FROM competition_round cr
    WHERE cr.competition_id = :competitionId AND (cr.stopwatch_status IS NOT NULL OR cr.stopwatch_first_started_at IS NOT NULL)
)
SQL;

        return (bool) $this->database->executeQuery($query, ['competitionId' => $competitionId])->fetchOne();
    }
}
