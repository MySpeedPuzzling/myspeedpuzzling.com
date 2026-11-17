<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\CompetitionDisplaysPresent;

/**
 * Does an event (or a round's event) have displays - and any showing results? One statement over the
 * `competition_display (competition_id)` index (competition toolkit D5, D-displays-overlays.md §7.3). Read through
 * CompetitionDisplaysPresence, which keeps the answer, never directly from a write path.
 */
readonly final class GetCompetitionDisplaysPresence
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function ofRound(string $roundId): CompetitionDisplaysPresent
    {
        if (Uuid::isValid($roundId) === false) {
            return CompetitionDisplaysPresent::none(null);
        }

        /** @var false|array{competition_id: string, displays: bool, results: bool} $row */
        $row = $this->database->executeQuery(
            <<<SQL
SELECT
    r.competition_id,
    EXISTS (SELECT 1 FROM competition_display d WHERE d.competition_id = r.competition_id) AS displays,
    EXISTS (SELECT 1 FROM competition_display d WHERE d.competition_id = r.competition_id AND d.uses_results) AS results
FROM competition_round r
WHERE r.id = :roundId
SQL,
            ['roundId' => $roundId],
        )->fetchAssociative();

        if ($row === false) {
            return CompetitionDisplaysPresent::none(null);
        }

        return new CompetitionDisplaysPresent($row['competition_id'], (bool) $row['displays'], (bool) $row['results']);
    }

    public function ofCompetition(string $competitionId): CompetitionDisplaysPresent
    {
        if (Uuid::isValid($competitionId) === false) {
            return CompetitionDisplaysPresent::none(null);
        }

        /** @var array{displays: bool, results: bool} $row */
        $row = $this->database->executeQuery(
            <<<SQL
SELECT
    EXISTS (SELECT 1 FROM competition_display d WHERE d.competition_id = :competitionId) AS displays,
    EXISTS (SELECT 1 FROM competition_display d WHERE d.competition_id = :competitionId AND d.uses_results) AS results
SQL,
            ['competitionId' => $competitionId],
        )->fetchAssociative();

        return new CompetitionDisplaysPresent(strtolower($competitionId), (bool) $row['displays'], (bool) $row['results']);
    }
}
