<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Results\RoundResultEntryMember;
use SpeedPuzzling\Web\Services\OfficialResultsRanking;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * Every entry of a round with its official record, for the organiser's tools - live entry, results desk, seating,
 * the official results endpoints and their Mercure updates (docs/features/competitions-management/official-results.md).
 * Organiser tooling behind COMPETITION_EDIT: participant names as the organiser recorded them, blocks and private
 * profiles do not apply. Only people going to the event (CompetitionParticipantGoing) - removed ones and the waitlist
 * of a managed event are left out (a waitlisted person put into a round counts once the organiser gives them a spot;
 * GetRoundResultsOverview tells how many wait).
 *
 * Two statements: the entries, and for a pair/team round their members.
 */
readonly final class GetRoundResultEntries
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * In ranking order (OfficialResultsRanking): ranked entries by rank, then did not start, then no result yet; equal
     * places by table number (none last), then name.
     *
     * @return list<RoundResultEntry>
     * @throws CompetitionRoundNotFound
     */
    public function forRound(string $roundId): array
    {
        $category = $this->database->fetchOne('SELECT category FROM competition_round WHERE id = :roundId', [
            'roundId' => $roundId,
        ]);

        if (!is_string($category)) {
            throw new CompetitionRoundNotFound();
        }

        $entries = $category === 'solo' ? $this->people($roundId) : $this->teams($roundId);

        return self::ranked($entries);
    }

    /**
     * The given entries of the round (ranked within the whole round), in ranking order. Refs of other rounds are
     * left out.
     *
     * @param array<string> $refs RoundEntryRef strings
     * @return list<RoundResultEntry>
     */
    public function byRefs(string $roundId, array $refs): array
    {
        if ($refs === []) {
            return [];
        }

        $wanted = array_fill_keys(array_map(strtolower(...), $refs), true);

        return array_values(array_filter(
            $this->forRound($roundId),
            static fn (RoundResultEntry $entry): bool => isset($wanted[$entry->ref->toString()]),
        ));
    }

    /**
     * @param list<RoundResultEntry> $entries
     * @return list<RoundResultEntry>
     */
    private static function ranked(array $entries): array
    {
        $ranks = OfficialResultsRanking::rank(array_map(static fn (RoundResultEntry $entry): RoundEntryResult => $entry->result, $entries));

        $rankedEntries = [];
        foreach ($entries as $index => $entry) {
            $rankedEntries[] = new RoundResultEntry(
                ref: $entry->ref,
                kind: $entry->kind,
                roundId: $entry->roundId,
                name: $entry->name,
                participantId: $entry->participantId,
                country: $entry->country,
                countries: $entry->countries,
                members: $entry->members,
                playerId: $entry->playerId,
                playerCode: $entry->playerCode,
                playerName: $entry->playerName,
                tableNumber: $entry->tableNumber,
                result: $entry->result,
                rank: $ranks[$index],
                qualifiedAt: $entry->qualifiedAt,
                resultEnteredAt: $entry->resultEnteredAt,
                resultEnteredById: $entry->resultEnteredById,
                resultEnteredByName: $entry->resultEnteredByName,
            );
        }

        usort($rankedEntries, static function (RoundResultEntry $a, RoundResultEntry $b): int {
            return OfficialResultsRanking::compare($a->result, $b->result)
                ?: [$a->tableNumber === null, $a->tableNumber] <=> [$b->tableNumber === null, $b->tableNumber]
                ?: strcasecmp($a->displayName(), $b->displayName())
                ?: strcmp($a->ref->id, $b->ref->id);
        });

        return $rankedEntries;
    }

    /**
     * @return list<RoundResultEntry>
     */
    private function people(string $roundId): array
    {
        $going = CompetitionParticipantGoing::sql('cp');
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    cpr.id,
    cpr.table_number,
    cpr.result_seconds,
    cpr.result_pieces_placed,
    cpr.result_did_not_start,
    cpr.result_entered_at,
    cpr.result_entered_by_id,
    entered_by.name AS entered_by_name,
    entered_by.code AS entered_by_code,
    cpr.qualified_at,
    cp.id AS participant_id,
    cp.name AS participant_name,
    cp.country AS participant_country,
    p.id AS player_id,
    p.code AS player_code,
    p.name AS player_name
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND {$going}
LEFT JOIN player p ON p.id = cp.player_id
LEFT JOIN player entered_by ON entered_by.id = cpr.result_entered_by_id
WHERE cpr.round_id = :roundId
SQL,
            ['roundId' => $roundId],
        );

        $entries = [];
        foreach ($rows as $row) {
            /** @var array{id: string, table_number: null|int, result_seconds: null|int, result_pieces_placed: null|int, result_did_not_start: bool, result_entered_at: null|string, result_entered_by_id: null|string, entered_by_name: null|string, entered_by_code: null|string, qualified_at: null|string, participant_id: string, participant_name: string, participant_country: null|string, player_id: null|string, player_code: null|string, player_name: null|string} $row */
            $entries[] = new RoundResultEntry(
                ref: RoundEntryRef::participantRound($row['id']),
                kind: RoundResultEntry::KIND_PERSON,
                roundId: $roundId,
                name: $row['participant_name'],
                participantId: $row['participant_id'],
                country: $row['participant_country'],
                countries: $row['participant_country'] !== null ? [$row['participant_country']] : [],
                members: [],
                playerId: $row['player_id'],
                playerCode: $row['player_code'],
                playerName: $row['player_name'],
                tableNumber: $row['table_number'],
                result: RoundEntryResult::fromColumns($row['result_seconds'], $row['result_pieces_placed'], $row['result_did_not_start']),
                rank: null,
                qualifiedAt: self::date($row['qualified_at']),
                resultEnteredAt: self::date($row['result_entered_at']),
                resultEnteredById: $row['result_entered_by_id'],
                resultEnteredByName: $row['entered_by_name'] ?? self::code($row['entered_by_code']),
            );
        }

        return $entries;
    }

    /**
     * @return list<RoundResultEntry>
     */
    private function teams(string $roundId): array
    {
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    ct.id,
    ct.name,
    ct.table_number,
    ct.result_seconds,
    ct.result_pieces_placed,
    ct.result_did_not_start,
    ct.result_entered_at,
    ct.result_entered_by_id,
    entered_by.name AS entered_by_name,
    entered_by.code AS entered_by_code,
    ct.qualified_at
FROM competition_team ct
LEFT JOIN player entered_by ON entered_by.id = ct.result_entered_by_id
WHERE ct.round_id = :roundId
SQL,
            ['roundId' => $roundId],
        );

        $going = CompetitionParticipantGoing::sql('cp');
        $memberRows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    cpr.team_id,
    cpr.id AS participant_round_id,
    cp.id AS participant_id,
    cp.name AS participant_name,
    cp.country AS participant_country,
    p.id AS player_id,
    p.code AS player_code,
    p.name AS player_name
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND {$going}
LEFT JOIN player p ON p.id = cp.player_id
WHERE cpr.round_id = :roundId
    AND cpr.team_id IS NOT NULL
ORDER BY cp.name, cp.id
SQL,
            ['roundId' => $roundId],
        );

        /** @var array<string, list<RoundResultEntryMember>> $members */
        $members = [];
        foreach ($memberRows as $row) {
            /** @var array{team_id: string, participant_round_id: string, participant_id: string, participant_name: string, participant_country: null|string, player_id: null|string, player_code: null|string, player_name: null|string} $row */
            $members[$row['team_id']][] = new RoundResultEntryMember(
                participantId: $row['participant_id'],
                participantRoundId: $row['participant_round_id'],
                name: $row['participant_name'],
                country: $row['participant_country'],
                playerId: $row['player_id'],
                playerCode: $row['player_code'],
                playerName: $row['player_name'],
            );
        }

        $entries = [];
        foreach ($rows as $row) {
            /** @var array{id: string, name: null|string, table_number: null|int, result_seconds: null|int, result_pieces_placed: null|int, result_did_not_start: bool, result_entered_at: null|string, result_entered_by_id: null|string, entered_by_name: null|string, entered_by_code: null|string, qualified_at: null|string} $row */
            $teamMembers = $members[$row['id']] ?? [];
            $countries = [];
            foreach ($teamMembers as $member) {
                if ($member->country !== null && !in_array($member->country, $countries, true)) {
                    $countries[] = $member->country;
                }
            }

            $entries[] = new RoundResultEntry(
                ref: RoundEntryRef::team($row['id']),
                kind: RoundResultEntry::KIND_TEAM,
                roundId: $roundId,
                name: $row['name'],
                participantId: null,
                country: null,
                countries: $countries,
                members: $teamMembers,
                playerId: null,
                playerCode: null,
                playerName: null,
                tableNumber: $row['table_number'],
                result: RoundEntryResult::fromColumns($row['result_seconds'], $row['result_pieces_placed'], $row['result_did_not_start']),
                rank: null,
                qualifiedAt: self::date($row['qualified_at']),
                resultEnteredAt: self::date($row['result_entered_at']),
                resultEnteredById: $row['result_entered_by_id'],
                resultEnteredByName: $row['entered_by_name'] ?? self::code($row['entered_by_code']),
            );
        }

        return $entries;
    }

    private static function date(null|string $value): null|DateTimeImmutable
    {
        return $value !== null ? new DateTimeImmutable($value) : null;
    }

    private static function code(null|string $code): null|string
    {
        return $code !== null ? '#' . strtoupper($code) : null;
    }
}
