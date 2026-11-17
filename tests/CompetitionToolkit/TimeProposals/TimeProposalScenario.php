<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit\TimeProposals;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ProposeRoundTime;
use SpeedPuzzling\Web\Message\RecordRoundResults;
use SpeedPuzzling\Web\Message\WithdrawTimeProposal;
use SpeedPuzzling\Web\Results\RecordedRoundResults;
use SpeedPuzzling\Web\Results\TimeProposalAnswer;
use SpeedPuzzling\Web\Services\RoundResultChangesParser;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Time proposals on the Results Cup (competition toolkit P1): the Final (solo, Anna - linked to PLAYER_ADMIN 20 days
 * ago - without a result) and the Pairs round (the unnamed pair Eva + Filip without a result; the Puzzle Sharks Anna +
 * Ben with one). The suite's clock is frozen at TestClock::FROZEN_NOW.
 *
 * Everything is set up in SQL before the first dispatch, and the entity manager is cleared between steps - as between
 * requests.
 */
trait TimeProposalScenario
{
    private const string ANNA_FINAL = 'participant_round:' . OfficialResultsFixture::ENTRY_FINAL_ANNA;
    private const string UNNAMED_PAIR = 'team:' . OfficialResultsFixture::TEAM_UNNAMED;

    private static function bus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }

    private static function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private static function nowMs(): int
    {
        return TestClock::milliseconds();
    }

    private static function proposalsOn(bool $on = true): void
    {
        self::db()->executeStatement(
            'UPDATE competition SET competitor_pages = :on, time_proposals = :on WHERE id = :id',
            ['on' => $on, 'id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP],
            ['on' => \Doctrine\DBAL\ParameterType::BOOLEAN],
        );
    }

    /**
     * The round's stopwatch: running since $startedAt (also a future start - the countdown), or 'stopped' (paused)
     */
    private static function stopwatch(string $roundId, null|DateTimeImmutable $startedAt, string $status = 'running', null|DateTimeImmutable $stoppedAt = null): void
    {
        self::db()->executeStatement(
            'UPDATE competition_round SET stopwatch_status = :status, stopwatch_started_at = :startedAt, stopwatch_stopped_at = :stoppedAt WHERE id = :id',
            [
                'status' => $startedAt === null ? null : $status,
                'startedAt' => $startedAt?->format('Y-m-d H:i:s'),
                'stoppedAt' => $stoppedAt?->format('Y-m-d H:i:s'),
                'id' => $roundId,
            ],
        );
    }

    private static function link(string $participantId, string $playerId, DateTimeImmutable $at): void
    {
        self::db()->executeStatement(
            'UPDATE competition_participant SET player_id = :player, connected_at = :at WHERE id = :id',
            ['player' => $playerId, 'at' => $at->format('Y-m-d H:i:s'), 'id' => $participantId],
        );
    }

    /**
     * Proposals on, the Final running for 10 minutes, the Pairs round too, Filip (of the unnamed pair) linked to
     * PLAYER_REGULAR before the start
     */
    private static function runningRounds(): void
    {
        self::proposalsOn();
        self::stopwatch(OfficialResultsFixture::ROUND_FINAL, TestClock::now()->modify('-10 minutes'));
        self::stopwatch(OfficialResultsFixture::ROUND_PAIRS, TestClock::now()->modify('-10 minutes'));
        self::link(OfficialResultsFixture::PARTICIPANT_FILIP, PlayerFixture::PLAYER_REGULAR, TestClock::now()->modify('-1 day'));
    }

    private static function newProposalId(int $offsetMs = 0): string
    {
        return Uuid::uuid7(new DateTimeImmutable('@' . intdiv(self::nowMs() + $offsetMs, 1000)))->toString();
    }

    private static function propose(
        string $entry,
        string $playerId = PlayerFixture::PLAYER_ADMIN,
        null|string $proposalId = null,
        int $tappedAgoMs = 2000,
        int $receivedAgoMs = 0,
        string $roundId = OfficialResultsFixture::ROUND_FINAL,
        null|string $stateHash = null,
    ): TimeProposalAnswer {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $answer = self::bus()->dispatch(new ProposeRoundTime(
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            roundId: $roundId,
            entry: $entry,
            proposalId: $proposalId ?? self::newProposalId(),
            actingPlayerId: $playerId,
            tappedAtMs: self::nowMs() - $tappedAgoMs,
            receivedAtMs: self::nowMs() - $receivedAgoMs,
            offsetMs: 12.5,
            rttMs: 80.0,
            stateHash: $stateHash,
        ))->last(HandledStamp::class)?->getResult();
        assert($answer instanceof TimeProposalAnswer);

        return $answer;
    }

    private static function withdraw(string $proposalId, string $playerId = PlayerFixture::PLAYER_ADMIN): TimeProposalAnswer
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $answer = self::bus()->dispatch(new WithdrawTimeProposal(OfficialResultsFixture::COMPETITION_RESULTS_CUP, $proposalId, $playerId))
            ->last(HandledStamp::class)?->getResult();
        assert($answer instanceof TimeProposalAnswer);

        return $answer;
    }

    /**
     * @param list<array<string, mixed>> $changes wire format
     */
    private static function record(array $changes, string $roundId = OfficialResultsFixture::ROUND_FINAL, bool $resultsOnly = false, string $actingPlayerId = PlayerFixture::PLAYER_WITH_STRIPE): RecordedRoundResults
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $recorded = self::bus()->dispatch(new RecordRoundResults(
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            roundId: $roundId,
            actingPlayerId: $actingPlayerId,
            changes: RoundResultChangesParser::parse($changes),
            resultsOnly: $resultsOnly,
        ))->last(HandledStamp::class)?->getResult();
        assert($recorded instanceof RecordedRoundResults);

        return $recorded;
    }

    /**
     * @param null|array<string, int|bool> $from
     * @param null|array<string, int|bool> $to
     * @return array<string, mixed>
     */
    private static function resultChange(string $entry, null|array $from, null|array $to, null|string $proposalId = null, null|string $clientChangeId = null): array
    {
        return [
            'clientChangeId' => $clientChangeId ?? Uuid::uuid7()->toString(),
            'entry' => $entry,
            'field' => 'result',
            'from' => $from,
            'to' => $to,
            ...($proposalId !== null ? ['proposalId' => $proposalId] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rejectChange(string $entry, string $proposalId, string $reason = 'wrong_entry', null|string $clientChangeId = null): array
    {
        return [
            'clientChangeId' => $clientChangeId ?? Uuid::uuid7()->toString(),
            'entry' => $entry,
            'field' => 'proposal',
            'from' => ['id' => $proposalId],
            'to' => ['status' => 'rejected', 'reason' => $reason],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function proposalRow(string $proposalId): array
    {
        $row = self::db()->fetchAssociative('SELECT * FROM competition_time_proposal WHERE id = :id', ['id' => $proposalId]);
        assert(is_array($row));

        return $row;
    }

    private static function proposalCount(): int
    {
        $count = self::db()->fetchOne('SELECT COUNT(*) FROM competition_time_proposal');
        assert(is_int($count));

        return $count;
    }
}
