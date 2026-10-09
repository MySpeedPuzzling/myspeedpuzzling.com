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
 * always go back (it hides only its own page). One statement for an event and for a whole series alike.
 */
readonly final class UnpublishBlockers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * The same three counts as columns of a statement listing competitions (`blocking_participants`,
     * `blocking_results`, `blocking_solving_times`) - "You organize" reads them for every row in its one statement.
     * `$competitionId` is the SQL expression of the competition's id; a NULL id counts nothing.
     */
    public static function sqlColumns(string $competitionId): string
    {
        $entryHolds = OfficialResultsGuard::sqlEntryHoldsOfficialData('cpr');
        $teamHolds = OfficialResultsGuard::sqlEntryHoldsOfficialData('ct');

        return <<<SQL
(SELECT COUNT(*) FROM competition_participant cp WHERE cp.competition_id = {$competitionId} AND cp.deleted_at IS NULL) AS blocking_participants,
(SELECT COUNT(*) FROM competition_participant_round cpr INNER JOIN competition_round cr ON cr.id = cpr.round_id WHERE cr.competition_id = {$competitionId} AND {$entryHolds})
    + (SELECT COUNT(*) FROM competition_team ct INNER JOIN competition_round cr ON cr.id = ct.round_id WHERE cr.competition_id = {$competitionId} AND {$teamHolds}) AS blocking_results,
(SELECT COUNT(*) FROM puzzle_solving_time pst
    WHERE pst.competition_id = {$competitionId}
        OR pst.competition_round_id IN (SELECT cr.id FROM competition_round cr WHERE cr.competition_id = {$competitionId})) AS blocking_solving_times
SQL;
    }

    /**
     * @param array<string, mixed> $row a row with the columns of sqlColumns()
     */
    public static function checkOf(array $row): UnpublishCheck
    {
        $count = static fn (string $column): int => is_numeric($row[$column] ?? null) ? (int) $row[$column] : 0;

        return new UnpublishCheck($count('blocking_participants'), $count('blocking_results'), $count('blocking_solving_times'));
    }

    public function forCompetition(string $competitionId): UnpublishCheck
    {
        if (Uuid::isValid($competitionId) === false) {
            return new UnpublishCheck(0, 0, 0);
        }

        return $this->check('SELECT c.id FROM competition c WHERE c.id = :id', $competitionId);
    }

    /**
     * Every edition of the series - one statement, however many editions it has
     */
    public function forSeries(string $seriesId): UnpublishCheck
    {
        if (Uuid::isValid($seriesId) === false) {
            return new UnpublishCheck(0, 0, 0);
        }

        return $this->check('SELECT c.id FROM competition c WHERE c.series_id = :id', $seriesId);
    }

    /**
     * @param string $competitions the competitions checked, as a statement with the parameter `:id`
     */
    private function check(string $competitions, string $id): UnpublishCheck
    {
        $entryHolds = OfficialResultsGuard::sqlEntryHoldsOfficialData('cpr');
        $teamHolds = OfficialResultsGuard::sqlEntryHoldsOfficialData('ct');

        $counts = $this->database->fetchAssociative(
            <<<SQL
WITH checked AS ({$competitions}),
    checked_rounds AS (SELECT cr.id FROM competition_round cr WHERE cr.competition_id IN (SELECT id FROM checked))
SELECT
    (SELECT COUNT(*) FROM competition_participant cp
        WHERE cp.competition_id IN (SELECT id FROM checked) AND cp.deleted_at IS NULL) AS participants,
    (SELECT COUNT(*) FROM competition_participant_round cpr WHERE cpr.round_id IN (SELECT id FROM checked_rounds) AND {$entryHolds})
        + (SELECT COUNT(*) FROM competition_team ct WHERE ct.round_id IN (SELECT id FROM checked_rounds) AND {$teamHolds}) AS results,
    (SELECT COUNT(*) FROM puzzle_solving_time pst
        WHERE pst.competition_id IN (SELECT id FROM checked)
            OR pst.competition_round_id IN (SELECT id FROM checked_rounds)) AS solving_times
SQL,
            ['id' => $id],
        );

        return new UnpublishCheck(
            is_array($counts) && is_numeric($counts['participants']) ? (int) $counts['participants'] : 0,
            is_array($counts) && is_numeric($counts['results']) ? (int) $counts['results'] : 0,
            is_array($counts) && is_numeric($counts['solving_times']) ? (int) $counts['solving_times'] : 0,
        );
    }
}
