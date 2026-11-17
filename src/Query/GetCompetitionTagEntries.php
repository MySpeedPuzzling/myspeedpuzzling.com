<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\CompetitionTagEntry;
use SpeedPuzzling\Web\Value\CompetitionLineupId;
use SpeedPuzzling\Web\Value\RoundEntryRef;

/**
 * The round entries behind a scanned competition tag (docs/features/competitions-management/toolkit/
 * B-qr-competitor-page.md §3, CompetitionTagResolver), one statement per tag, in any round of the event: a solo
 * round's entry is the person's CompetitionParticipantRound, a pair/team round's the CompetitionTeam. People removed
 * from the event are nobody's entry. Reads ids and table numbers only - no names - so it is the same for every viewer;
 * who may see what is the caller's business (the scan routes, organiser tooling and the competitor pages that 404 a
 * draft first).
 */
readonly final class GetCompetitionTagEntries
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * The rounds of an active participant of the event, with their entry in each (solo: theirs; pair/team: their
     * pair/team) - null for anybody else (unknown, another event's, removed). An empty list = a participant in no round.
     *
     * @return null|list<CompetitionTagEntry>
     */
    public function ofParticipant(string $competitionId, string $participantId): null|array
    {
        if (!Uuid::isValid($competitionId) || !Uuid::isValid($participantId)) {
            return null;
        }

        /** @var list<array{round_id: null|string, participant_round_id: null|string, team_id: null|string, category: null|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT cpr.round_id, cpr.id AS participant_round_id, cpr.team_id, cr.category
FROM competition_participant cp
LEFT JOIN competition_participant_round cpr ON cpr.participant_id = cp.id
LEFT JOIN competition_round cr ON cr.id = cpr.round_id
WHERE cp.id = :participantId
    AND cp.competition_id = :competitionId
    AND cp.deleted_at IS NULL
ORDER BY cpr.round_id
SQL,
            ['participantId' => $participantId, 'competitionId' => $competitionId],
        );

        if ($rows === []) {
            return null;
        }

        $entries = [];

        foreach ($rows as $row) {
            if ($row['round_id'] === null || $row['participant_round_id'] === null) {
                continue;
            }

            $entries[] = new CompetitionTagEntry($row['round_id'], match (true) {
                $row['category'] === 'solo' => RoundEntryRef::participantRound($row['participant_round_id']),
                $row['team_id'] !== null => RoundEntryRef::team($row['team_id']),
                default => null,
            });
        }

        return $entries;
    }

    /**
     * Who sits at the table in each round of the event that uses it (table numbers are unique within a round).
     *
     * @return list<CompetitionTagEntry>
     */
    public function atTable(string $competitionId, int $tableNumber): array
    {
        if (!Uuid::isValid($competitionId)) {
            return [];
        }

        /** @var list<array{round_id: string, kind: string, id: string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            strtr(<<<SQL
SELECT cpr.round_id, 'participant_round' AS kind, cpr.id
FROM competition_participant_round cpr
INNER JOIN competition_round cr ON cr.id = cpr.round_id AND cr.category = 'solo'
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND %going%
WHERE cr.competition_id = :competitionId
    AND cpr.table_number = :tableNumber
UNION ALL
SELECT ct.round_id, 'team' AS kind, ct.id
FROM competition_team ct
INNER JOIN competition_round cr ON cr.id = ct.round_id AND cr.category <> 'solo'
WHERE cr.competition_id = :competitionId
    AND ct.table_number = :tableNumber
ORDER BY round_id
SQL, ['%going%' => CompetitionParticipantGoing::sql('cp')]),
            ['competitionId' => $competitionId, 'tableNumber' => $tableNumber],
        );

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * One round entry of the event by its id - a solo round's CompetitionParticipantRound or a pair/team round's
     * CompetitionTeam (a pair/team member's own row stands for their pair/team). Null for anything else.
     */
    public function entry(string $competitionId, string $entryId): null|CompetitionTagEntry
    {
        if (!Uuid::isValid($competitionId) || !Uuid::isValid($entryId)) {
            return null;
        }

        /** @var false|array{round_id: string, kind: string, id: string} $row */
        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT cpr.round_id, CASE WHEN cr.category = 'solo' THEN 'participant_round' ELSE 'team' END AS kind, CASE WHEN cr.category = 'solo' THEN cpr.id ELSE cpr.team_id END AS id
FROM competition_participant_round cpr
INNER JOIN competition_round cr ON cr.id = cpr.round_id
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
WHERE cpr.id = :entryId
    AND cr.competition_id = :competitionId
    AND (cr.category = 'solo' OR cpr.team_id IS NOT NULL)
UNION ALL
SELECT ct.round_id, 'team' AS kind, ct.id
FROM competition_team ct
INNER JOIN competition_round cr ON cr.id = ct.round_id AND cr.category <> 'solo'
WHERE ct.id = :entryId
    AND cr.competition_id = :competitionId
SQL,
            ['competitionId' => $competitionId, 'entryId' => $entryId],
        );

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * The pairs/teams of the event whose exact member set is the line-up (CompetitionLineupId), one per round they play
     * in. The ids are hashed here: the line-up is never stored.
     *
     * @return list<CompetitionTagEntry>
     */
    public function ofLineup(string $competitionId, string $lineupId): array
    {
        if (!Uuid::isValid($competitionId) || !Uuid::isValid($lineupId)) {
            return [];
        }

        /** @var list<array{round_id: string, id: string, members: string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT ct.round_id, ct.id, string_agg(cpr.participant_id::text, ',') AS members
FROM competition_team ct
INNER JOIN competition_round cr ON cr.id = ct.round_id AND cr.category <> 'solo'
INNER JOIN competition_participant_round cpr ON cpr.team_id = ct.id
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
WHERE cr.competition_id = :competitionId
GROUP BY ct.round_id, ct.id
ORDER BY ct.round_id
SQL,
            ['competitionId' => $competitionId],
        );

        $lineupId = strtolower($lineupId);
        $entries = [];

        foreach ($rows as $row) {
            if (CompetitionLineupId::of($competitionId, explode(',', $row['members'])) === $lineupId) {
                $entries[] = new CompetitionTagEntry($row['round_id'], RoundEntryRef::team($row['id']));
            }
        }

        return $entries;
    }

    /**
     * @param array{round_id: string, kind: string, id: string} $row
     */
    private static function hydrate(array $row): CompetitionTagEntry
    {
        return new CompetitionTagEntry(
            $row['round_id'],
            $row['kind'] === RoundEntryRef::TEAM ? RoundEntryRef::team($row['id']) : RoundEntryRef::participantRound($row['id']),
        );
    }
}
