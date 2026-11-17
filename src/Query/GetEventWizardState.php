<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Results\EventWizardState;

/**
 * One statement: what the event creation wizard needs to know about an event to find its path and its steps' state
 * (EventWizardSteps). Plain DBAL on purpose: it also answers after a failed insert closed the entity manager (two submits
 * of the same new page at once). Organiser tooling - the callers check COMPETITION_EDIT; a draft (`is_draft`) is what the wizard
 * works on, a published event is sent to its edit page.
 */
readonly final class GetEventWizardState
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     */
    public function of(string $competitionId): EventWizardState
    {
        if (Uuid::isValid($competitionId) === false) {
            throw new CompetitionNotFound();
        }

        $query = <<<SQL
SELECT
    c.is_draft,
    c.added_by_player_id::text AS added_by_player_id,
    c.series_id IS NOT NULL AS is_edition,
    (SELECT COUNT(*) FROM competition_round cr WHERE cr.competition_id = c.id) AS rounds_count,
    (SELECT MIN(cr.id::text) FROM competition_round cr WHERE cr.competition_id = c.id) AS first_round_id,
    (SELECT COUNT(*) FROM competition_round_puzzle crp JOIN competition_round cr ON cr.id = crp.round_id WHERE cr.competition_id = c.id) AS puzzles_count,
    (
        c.registration_managed
        OR c.registration_link IS NOT NULL
        OR c.results_link IS NOT NULL
        OR c.competitor_pages
        OR c.qr_tag_mode IS NOT NULL
        OR EXISTS (SELECT 1 FROM competition_referee r WHERE r.competition_id = c.id)
        OR EXISTS (SELECT 1 FROM competition_display d WHERE d.competition_id = c.id)
        OR EXISTS (SELECT 1 FROM competition_standing s WHERE s.competition_id = c.id)
    ) AS extras_on
FROM competition c
WHERE c.id = :competitionId
SQL;

        /** @var false|array{is_draft: bool, added_by_player_id: null|string, is_edition: bool, rounds_count: int|string, first_round_id: null|string, puzzles_count: int|string, extras_on: bool} $row */
        $row = $this->database->executeQuery($query, ['competitionId' => $competitionId])->fetchAssociative();

        if ($row === false) {
            throw new CompetitionNotFound();
        }

        $roundsCount = (int) $row['rounds_count'];

        return new EventWizardState(
            competitionId: strtolower($competitionId),
            isDraft: (bool) $row['is_draft'],
            isEdition: (bool) $row['is_edition'],
            roundsCount: $roundsCount,
            singleRoundId: $roundsCount === 1 ? $row['first_round_id'] : null,
            roundPuzzlesCount: (int) $row['puzzles_count'],
            addedByPlayerId: $row['added_by_player_id'],
            extrasOn: (bool) $row['extras_on'],
        );
    }
}
