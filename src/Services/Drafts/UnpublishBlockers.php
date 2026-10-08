<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Drafts;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\UnpublishCheck;
use SpeedPuzzling\Web\Services\OfficialResultsGuard;

/**
 * What keeps an event, or a series through any of its editions, from going back to draft (docs/features/organizations/
 * README.md "Drafts"): participants who joined (not deleted), entries with official results or qualified marks, and
 * linked solving times - suspicious ones included: a hidden page must not hold anybody's result. An organization can
 * always go back (it hides only its own page).
 */
readonly final class UnpublishBlockers
{
    public function __construct(
        private Connection $database,
        private OfficialResultsGuard $officialResultsGuard,
    ) {
    }

    public function forCompetition(string $competitionId): UnpublishCheck
    {
        if (Uuid::isValid($competitionId) === false) {
            return new UnpublishCheck(0, 0, 0);
        }

        return $this->check([$competitionId]);
    }

    public function forSeries(string $seriesId): UnpublishCheck
    {
        if (Uuid::isValid($seriesId) === false) {
            return new UnpublishCheck(0, 0, 0);
        }

        /** @var list<string> $competitionIds */
        $competitionIds = $this->database->fetchFirstColumn(
            'SELECT id FROM competition WHERE series_id = :seriesId',
            ['seriesId' => $seriesId],
        );

        return $this->check($competitionIds);
    }

    /**
     * @param list<string> $competitionIds
     */
    private function check(array $competitionIds): UnpublishCheck
    {
        $participants = 0;
        $results = 0;
        $solvingTimes = 0;

        foreach ($competitionIds as $competitionId) {
            $counts = $this->database->fetchAssociative(
                <<<SQL
SELECT
    (SELECT COUNT(*) FROM competition_participant cp WHERE cp.competition_id = :id AND cp.deleted_at IS NULL) AS participants,
    (SELECT COUNT(*) FROM puzzle_solving_time pst
        WHERE pst.competition_id = :id
            OR pst.competition_round_id IN (SELECT cr.id FROM competition_round cr WHERE cr.competition_id = :id)) AS solving_times
SQL,
                ['id' => $competitionId],
            );

            $participants += is_array($counts) && is_numeric($counts['participants']) ? (int) $counts['participants'] : 0;
            $solvingTimes += is_array($counts) && is_numeric($counts['solving_times']) ? (int) $counts['solving_times'] : 0;
            $results += $this->officialResultsGuard->countEntriesWithOfficialDataInCompetition($competitionId);
        }

        return new UnpublishCheck($participants, $results, $solvingTimes);
    }
}
