<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionTimeProposal;
use SpeedPuzzling\Web\Results\TimeProposalView;
use SpeedPuzzling\Web\Value\RoundEntryRef;

/**
 * Time proposals of a round for the judges' tools and the competitor's own answers (competition toolkit P1,
 * TimeProposalView). Organiser tooling: names as the organiser recorded them, nothing of a linked player's profile -
 * so the same rows are safe on the referees' Mercure topic. Reads no competition row (the caller authorised the round's
 * event, and the round state reads proposals only while the competition has them on). One statement per call.
 */
readonly final class GetTimeProposals
{
    // A decided proposal stays in the round state this long, so a judge sees "confirmed by Petra" right after
    public const string RECENTLY_DECIDED = '-10 minutes';

    private const string SELECT = <<<SQL
SELECT
    tp.id,
    tp.round_id,
    tp.participant_round_id,
    tp.team_id,
    COALESCE(cpr.table_number, ct.table_number) AS table_number,
    tp.status,
    tp.proposed_seconds,
    tp.receipt_seconds,
    tp.tapped_at_ms,
    tp.received_at_ms,
    tp.late_by_ms,
    tp.clock_changed,
    tp.timing_verdict,
    tp.last_heard_at_ms,
    tp.clock_uncertain,
    tp.queued_offline,
    tp.send_attempts,
    tp.proposed_by_participant_id,
    cp.name AS proposed_by_name,
    tp.proposer_linked_at,
    tp.decided_at,
    tp.decided_by_player_id,
    decider.name AS decided_by_name,
    decider.code AS decided_by_code,
    tp.decided_seconds,
    tp.decision_reason
FROM competition_time_proposal tp
INNER JOIN competition_participant cp ON cp.id = tp.proposed_by_participant_id
LEFT JOIN competition_participant_round cpr ON cpr.id = tp.participant_round_id
LEFT JOIN competition_team ct ON ct.id = tp.team_id
LEFT JOIN player decider ON decider.id = tp.decided_by_player_id
SQL;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * The round's pending proposals and those decided in the last 10 minutes, oldest tap first.
     *
     * @return list<TimeProposalView>
     */
    public function forRound(string $roundId, DateTimeImmutable $now): array
    {
        if (!Uuid::isValid($roundId)) {
            return [];
        }

        $rows = $this->database->fetchAllAssociative(
            self::SELECT . "\nWHERE tp.round_id = :roundId AND (tp.status = 'pending' OR tp.decided_at >= :since)\nORDER BY tp.tapped_at_ms, tp.id",
            ['roundId' => $roundId, 'since' => $now->modify(self::RECENTLY_DECIDED)->format('Y-m-d H:i:s')],
        );

        return array_map(self::view(...), $rows);
    }

    /**
     * The given proposals of the round, oldest tap first - ids of another round are left out.
     *
     * @param array<string> $proposalIds
     * @return list<TimeProposalView>
     */
    public function byIds(string $roundId, array $proposalIds): array
    {
        $proposalIds = array_values(array_unique(array_map(strtolower(...), array_filter($proposalIds, static fn (string $id): bool => Uuid::isValid($id)))));

        if ($proposalIds === [] || !Uuid::isValid($roundId)) {
            return [];
        }

        $rows = $this->database->fetchAllAssociative(
            self::SELECT . "\nWHERE tp.round_id = :roundId AND tp.id IN (:ids)\nORDER BY tp.tapped_at_ms, tp.id",
            ['roundId' => $roundId, 'ids' => $proposalIds],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_map(self::view(...), $rows);
    }

    /**
     * The competitor's own latest proposal per entry (competitor page, B8): the pending one, else the last tapped -
     * whatever its status, so the phone shows "confirmed", "not accepted" or "withdrawn". Keyed by the entry's ref.
     *
     * @param list<RoundEntryRef> $entries
     * @return array<string, TimeProposalView>
     */
    public function latestOfEntries(array $entries): array
    {
        $solo = [];
        $teams = [];

        foreach ($entries as $entry) {
            if ($entry->isTeam()) {
                $teams[] = strtolower($entry->id);
            } else {
                $solo[] = strtolower($entry->id);
            }
        }

        if ($solo === [] && $teams === []) {
            return [];
        }

        $rows = $this->database->fetchAllAssociative(
            'SELECT DISTINCT ON (COALESCE(latest.participant_round_id, latest.team_id)) * FROM (' . self::SELECT . "\nWHERE tp.participant_round_id IN (:solo) OR tp.team_id IN (:teams)) latest\n"
            . "ORDER BY COALESCE(latest.participant_round_id, latest.team_id), (latest.status = 'pending') DESC, latest.tapped_at_ms DESC, latest.id DESC",
            ['solo' => $solo === [] ? [Uuid::NIL] : $solo, 'teams' => $teams === [] ? [Uuid::NIL] : $teams],
            ['solo' => ArrayParameterType::STRING, 'teams' => ArrayParameterType::STRING],
        );

        $latest = [];

        foreach ($rows as $row) {
            $view = self::view($row);
            $latest[$view->entry] = $view;
        }

        return $latest;
    }

    /**
     * Where a proposal lives - for a controller that must lock its event before the dispatch.
     *
     * @return null|array{roundId: string, competitionId: string}
     */
    public function locate(string $proposalId): null|array
    {
        if (!Uuid::isValid($proposalId)) {
            return null;
        }

        /** @var false|array{round_id: string, competition_id: string} $row */
        $row = $this->database->fetchAssociative(
            'SELECT tp.round_id, r.competition_id FROM competition_time_proposal tp INNER JOIN competition_round r ON r.id = tp.round_id WHERE tp.id = :id',
            ['id' => strtolower($proposalId)],
        );

        return $row === false ? null : ['roundId' => $row['round_id'], 'competitionId' => $row['competition_id']];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function view(array $row): TimeProposalView
    {
        /** @var array{id: string, round_id: string, participant_round_id: null|string, team_id: null|string, table_number: null|int, status: string, proposed_seconds: int, receipt_seconds: int, tapped_at_ms: int|string, received_at_ms: int|string, late_by_ms: int, clock_changed: bool, timing_verdict: null|string, last_heard_at_ms: null|int|string, clock_uncertain: bool, queued_offline: bool, send_attempts: null|int, proposed_by_participant_id: string, proposed_by_name: string, proposer_linked_at: null|string, decided_at: null|string, decided_by_player_id: null|string, decided_by_name: null|string, decided_by_code: null|string, decided_seconds: null|int, decision_reason: null|string} $row */
        $entry = $row['team_id'] !== null ? RoundEntryRef::team($row['team_id']) : RoundEntryRef::participantRound((string) $row['participant_round_id']);

        return new TimeProposalView(
            id: $row['id'],
            roundId: $row['round_id'],
            entry: $entry->toString(),
            tableNumber: $row['table_number'],
            status: $row['status'],
            proposedSeconds: $row['proposed_seconds'],
            receiptSeconds: $row['receipt_seconds'],
            tappedAtMs: (int) $row['tapped_at_ms'],
            receivedAtMs: (int) $row['received_at_ms'],
            lateByMs: $row['late_by_ms'],
            late: $row['late_by_ms'] > CompetitionTimeProposal::LATE_AFTER_MS,
            clockChanged: $row['clock_changed'],
            timingVerdict: $row['timing_verdict'],
            lastHeardAtMs: $row['last_heard_at_ms'] !== null ? (int) $row['last_heard_at_ms'] : null,
            clockUncertain: $row['clock_uncertain'],
            queuedOffline: $row['queued_offline'],
            sendAttempts: $row['send_attempts'],
            proposedByParticipantId: $row['proposed_by_participant_id'],
            proposedByName: $row['proposed_by_name'],
            proposerLinkedAt: $row['proposer_linked_at'] !== null ? new DateTimeImmutable($row['proposer_linked_at']) : null,
            decidedAt: $row['decided_at'] !== null ? new DateTimeImmutable($row['decided_at']) : null,
            decidedById: $row['decided_by_player_id'],
            decidedByName: $row['decided_by_name'] ?? ($row['decided_by_code'] !== null ? '#' . strtoupper($row['decided_by_code']) : null),
            decidedSeconds: $row['decided_seconds'],
            decisionReason: $row['decision_reason'],
        );
    }
}
