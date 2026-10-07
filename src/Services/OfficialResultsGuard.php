<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Results\RoundResultEntry;

/**
 * Who holds official data - a result or a qualified mark - so that nothing removes it as a side effect
 * (docs/features/competitions-management/official-results.md, guards). A person holds it through their own entry of a
 * solo round, or as a member of a pair/team that holds it. Write-side lookups, no viewer.
 */
readonly final class OfficialResultsGuard
{
    private const string ENTRY_HAS_DATA = <<<SQL
(cpr.result_seconds IS NOT NULL OR cpr.result_pieces_placed IS NOT NULL OR cpr.result_did_not_start OR cpr.qualified_at IS NOT NULL
    OR ct.result_seconds IS NOT NULL OR ct.result_pieces_placed IS NOT NULL OR ct.result_did_not_start OR ct.qualified_at IS NOT NULL)
SQL;

    public function __construct(
        private Connection $database,
        private GetRoundResultEntries $getRoundResultEntries,
    ) {
    }

    /**
     * The round's entries with a result or a qualified mark, ranked - what deleting the round takes away, listed for
     * the organiser to confirm.
     *
     * @return list<RoundResultEntry>
     */
    public function entriesWithOfficialData(string $roundId): array
    {
        return array_values(array_filter(
            $this->getRoundResultEntries->forRound($roundId),
            static fn (RoundResultEntry $entry): bool => $entry->result->isNone() === false || $entry->isQualified(),
        ));
    }

    /**
     * Binds a confirmation to exactly the list it was given - also an empty one.
     *
     * @param list<RoundResultEntry> $entries
     */
    public static function hashEntries(array $entries): string
    {
        $lines = array_map(
            static fn (RoundResultEntry $entry): string => implode('|', [
                $entry->ref->toString(),
                json_encode($entry->result->toWire(), JSON_THROW_ON_ERROR),
                $entry->isQualified() ? 'q' : '-',
            ]),
            $entries,
        );
        sort($lines);

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * In any round of the event, or in the given round.
     */
    public function participantHasOfficialData(string $participantId, null|string $roundId = null): bool
    {
        $roundCondition = $roundId !== null ? 'AND cpr.round_id = :roundId' : '';
        $hasData = self::ENTRY_HAS_DATA;

        return $this->database->fetchOne(
            <<<SQL
SELECT 1
FROM competition_participant_round cpr
LEFT JOIN competition_team ct ON ct.id = cpr.team_id
WHERE cpr.participant_id = :participantId
    {$roundCondition}
    AND {$hasData}
LIMIT 1
SQL,
            array_filter(['participantId' => $participantId, 'roundId' => $roundId], static fn (null|string $value): bool => $value !== null),
        ) !== false;
    }

    /**
     * Participant id => the round ids they hold official data in, for every participant of the event that holds any.
     *
     * @return array<string, array<string, true>>
     */
    public function officialDataByParticipant(string $competitionId): array
    {
        $hasData = self::ENTRY_HAS_DATA;
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT cpr.participant_id, cpr.round_id
FROM competition_participant_round cpr
INNER JOIN competition_round cr ON cr.id = cpr.round_id
LEFT JOIN competition_team ct ON ct.id = cpr.team_id
WHERE cr.competition_id = :competitionId
    AND {$hasData}
SQL,
            ['competitionId' => $competitionId],
        );

        $holders = [];
        foreach ($rows as $row) {
            /** @var array{participant_id: string, round_id: string} $row */
            $holders[$row['participant_id']][$row['round_id']] = true;
        }

        return $holders;
    }

    /**
     * Pairs/teams of the event with a result or a qualified mark.
     *
     * @return array<string, true> team id => true
     */
    public function teamsWithOfficialData(string $competitionId): array
    {
        /** @var list<string> $teamIds */
        $teamIds = $this->database->fetchFirstColumn(
            <<<SQL
SELECT ct.id
FROM competition_team ct
INNER JOIN competition_round cr ON cr.id = ct.round_id
WHERE cr.competition_id = :competitionId
    AND (ct.result_seconds IS NOT NULL OR ct.result_pieces_placed IS NOT NULL OR ct.result_did_not_start OR ct.qualified_at IS NOT NULL)
SQL,
            ['competitionId' => $competitionId],
        );

        return array_fill_keys($teamIds, true);
    }

    /**
     * Entries of the round with a result or a qualified mark - what a round's category change would strand (marks and
     * results sit on the entries of the round's kind) and what deleting the round would take away.
     */
    public function countEntriesWithOfficialDataInRound(string $roundId): int
    {
        return $this->count(<<<SQL
SELECT
    (SELECT COUNT(*) FROM competition_participant_round cpr
        WHERE cpr.round_id = :id AND (cpr.result_seconds IS NOT NULL OR cpr.result_pieces_placed IS NOT NULL OR cpr.result_did_not_start OR cpr.qualified_at IS NOT NULL))
    + (SELECT COUNT(*) FROM competition_team ct
        WHERE ct.round_id = :id AND (ct.result_seconds IS NOT NULL OR ct.result_pieces_placed IS NOT NULL OR ct.result_did_not_start OR ct.qualified_at IS NOT NULL))
SQL, $roundId);
    }

    /**
     * Entries of all the event's rounds with a result or a qualified mark - the internal API deletes only an event
     * nobody has a result in (DeleteCompetition::$refuseWhenItHasResults).
     */
    public function countEntriesWithOfficialDataInCompetition(string $competitionId): int
    {
        return $this->count(<<<SQL
SELECT
    (SELECT COUNT(*) FROM competition_participant_round cpr
        INNER JOIN competition_round cr ON cr.id = cpr.round_id
        WHERE cr.competition_id = :id AND (cpr.result_seconds IS NOT NULL OR cpr.result_pieces_placed IS NOT NULL OR cpr.result_did_not_start OR cpr.qualified_at IS NOT NULL))
    + (SELECT COUNT(*) FROM competition_team ct
        INNER JOIN competition_round cr ON cr.id = ct.round_id
        WHERE cr.competition_id = :id AND (ct.result_seconds IS NOT NULL OR ct.result_pieces_placed IS NOT NULL OR ct.result_did_not_start OR ct.qualified_at IS NOT NULL))
SQL, $competitionId);
    }

    private function count(string $sql, string $id): int
    {
        $count = $this->database->fetchOne($sql, ['id' => $id]);

        return is_numeric($count) ? (int) $count : 0;
    }
}
