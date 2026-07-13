<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleSpeedPercentiles;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\LevelTable;
use SpeedPuzzling\Web\Value\SolveXpContext;
use SpeedPuzzling\Web\Value\SpeedPercentile;
use SpeedPuzzling\Web\Value\XpEntryDraft;
use SpeedPuzzling\Web\Value\XpReason;

/**
 * Live XP wiring for the solve lifecycle:
 *
 *  - award (add):     append entries for one new solve, for every registered participant
 *  - rebuild (edit):  semantically delete+re-add — the (participant, puzzle) chain is wiped
 *                     and replayed for every affected participant, occurrence promotions in
 *                     both directions, removed team members cleaned up
 *  - compensate (delete): the deleted solve's entries stay as audit history and get exact
 *                     negative mirrors; the remaining chain is then rebuilt
 *
 * Weekly/daily counters are read from the ledger in canonical order ((earned_at, solve id)),
 * matching the full XpRecomputer replay; entries whose solve no longer exists never occupy
 * a weekly/daily slot.
 *
 * @phpstan-type SolveRow array{id: string, puzzle_id: string, player_id: string, seconds_to_solve: null|int, puzzling_type: string, unboxed: bool, pieces: int, difficulty_tier: null|int, tracked_at: string, earned_at: string, team: null|string, suspicious: bool}
 */
readonly final class XpChainRecomputer
{
    private const string SOLVE_SELECT = <<<SQL
SELECT
    pst.id,
    pst.puzzle_id,
    pst.player_id,
    pst.seconds_to_solve,
    pst.puzzling_type,
    pst.unboxed,
    COALESCE(pst.pieces_count_snapshot, p.pieces_count) AS pieces,
    pd.difficulty_tier,
    pst.tracked_at,
    COALESCE(pst.finished_at, pst.tracked_at) AS earned_at,
    pst.team,
    pst.suspicious
FROM puzzle_solving_time pst
JOIN puzzle p ON p.id = pst.puzzle_id
LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = pst.puzzle_id
SQL;

    /**
     * Same row shape as SOLVE_SELECT, but driven from awarded ledger entries so every
     * (earning player, solve) pair appears — including team participants.
     */
    private const string SOLVE_SELECT_FROM_ENTRIES = <<<SQL
SELECT
    pst.id,
    pst.puzzle_id,
    e.player_id,
    pst.seconds_to_solve,
    pst.puzzling_type,
    pst.unboxed,
    COALESCE(pst.pieces_count_snapshot, p.pieces_count) AS pieces,
    pd.difficulty_tier,
    pst.tracked_at,
    COALESCE(pst.finished_at, pst.tracked_at) AS earned_at,
    pst.team,
    pst.suspicious
FROM xp_entry e
JOIN puzzle_solving_time pst ON pst.id = e.solving_time_id
JOIN puzzle p ON p.id = pst.puzzle_id
LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = pst.puzzle_id
SQL;

    public function __construct(
        private Connection $database,
        private PlayerRepository $playerRepository,
        private XpCalculator $xpCalculator,
        private XpLedger $xpLedger,
        private GetPuzzleSpeedPercentiles $getPuzzleSpeedPercentiles,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function awardForNewSolve(string $solvingTimeId): void
    {
        $solve = $this->loadSolve($solvingTimeId);

        if ($solve === null || $solve['suspicious']) {
            return;
        }

        $earnedAt = new DateTimeImmutable($solve['earned_at']);

        foreach ($this->participantsOf($solve) as $participantId) {
            // Idempotency under at-least-once delivery: entries already exist → done.
            if ($this->hasAnyEntry($participantId, $solvingTimeId)) {
                continue;
            }

            try {
                $player = $this->playerRepository->get($participantId);
            } catch (PlayerNotFound) {
                continue;
            }

            $occurrence = $this->occurrenceIndex($participantId, $solve['puzzle_id'], $earnedAt, $solvingTimeId);

            $awards = $this->xpCalculator->calculate($this->contextFor(
                solve: $solve,
                participantId: $participantId,
                occurrence: $occurrence,
                earnedAt: $earnedAt,
            ));

            $drafts = [];
            foreach ($awards as $award) {
                $drafts[] = new XpEntryDraft(
                    reason: $award->reason,
                    amount: $award->amount,
                    earnedAt: $earnedAt,
                    inWeeklyDelta: true,
                    solvingTimeId: Uuid::fromString($solvingTimeId),
                );
            }

            $this->xpLedger->append($player, $drafts);
        }
    }

    public function rebuildChainForEditedSolve(string $solvingTimeId): void
    {
        $solve = $this->loadSolve($solvingTimeId);

        if ($solve === null) {
            // Solve got deleted before this message ran — the deletion flow owns cleanup.
            return;
        }

        $participants = array_values(array_unique([
            ...$this->participantsOf($solve),
            ...$this->playersWithEntriesFor($solvingTimeId),
        ]));

        foreach ($participants as $participantId) {
            $this->rebuildPair($participantId, $solve['puzzle_id'], [$solvingTimeId]);
        }
    }

    public function compensateAndRebuildAfterDeletion(string $solvingTimeId, string $puzzleId): void
    {
        $affectedPlayers = $this->playersWithEntriesFor($solvingTimeId);

        foreach ($affectedPlayers as $participantId) {
            $this->compensateEntries($participantId, $solvingTimeId);
        }

        foreach ($affectedPlayers as $participantId) {
            $this->rebuildPair($participantId, $puzzleId, []);
        }
    }

    /**
     * Ex-post settlements (§1.4): a solve logged on an unrated puzzle earns its difficulty
     * bonus once the puzzle first gets a difficulty tier; a solve on a puzzle without a
     * reliable median earns its speed bonus once ≥3 distinct solvers exist. Settled once,
     * frozen forever (anchored by the unique (player, solve, reason) index) — later tier or
     * median drift never revises anything. Go-forward solves only; settlements never count
     * toward the weekly delta and carry the settlement run time as earned_at.
     *
     * Returns the number of settlement entries created.
     */
    public function settlePendingBonuses(): int
    {
        $now = $this->clock->now();
        $created = 0;

        /** @var array<string, list<XpEntryDraft>> $draftsByPlayer */
        $draftsByPlayer = [];

        foreach ($this->loadPendingDifficultySettlements() as $row) {
            $amount = $this->settlementAmount($row, XpReason::SolveDifficultyBonus);

            if ($amount <= 0) {
                continue;
            }

            $draftsByPlayer[$row['player_id']][] = new XpEntryDraft(
                reason: XpReason::DifficultySettlement,
                amount: $amount,
                earnedAt: $now,
                inWeeklyDelta: false,
                solvingTimeId: Uuid::fromString($row['id']),
            );
            $created++;
        }

        foreach ($this->loadPendingSpeedSettlements() as $row) {
            $amount = $this->settlementAmount($row, XpReason::SolveSpeedBonus);

            if ($amount <= 0) {
                continue;
            }

            $draftsByPlayer[$row['player_id']][] = new XpEntryDraft(
                reason: XpReason::SpeedSettlement,
                amount: $amount,
                earnedAt: $now,
                inWeeklyDelta: false,
                solvingTimeId: Uuid::fromString($row['id']),
            );
            $created++;
        }

        foreach ($draftsByPlayer as $playerId => $drafts) {
            try {
                $player = $this->playerRepository->get($playerId);
            } catch (PlayerNotFound) {
                continue;
            }

            $this->xpLedger->append($player, $drafts);
        }

        return $created;
    }

    /**
     * Runs the full calculator for the solve as it stands today and extracts the single
     * receipt line being settled — guarantees settlement amounts use the exact same math
     * and rounding as live awards.
     *
     * @phpstan-param SolveRow $row
     */
    private function settlementAmount(array $row, XpReason $wantedReason): int
    {
        $earnedAt = new DateTimeImmutable($row['earned_at']);
        $occurrence = $this->occurrenceIndex($row['player_id'], $row['puzzle_id'], $earnedAt, $row['id']);

        $isTeamOrDuo = $row['puzzling_type'] !== 'solo';
        $speedPercentile = SpeedPercentile::None;

        if ($wantedReason === XpReason::SolveSpeedBonus && $isTeamOrDuo === false && $row['seconds_to_solve'] !== null) {
            $speedPercentile = $this->resolveSpeedPercentile($row, $row['player_id']);
        }

        $awards = $this->xpCalculator->calculate(new SolveXpContext(
            piecesCount: $row['pieces'],
            difficultyTier: $row['difficulty_tier'],
            isTimed: $row['seconds_to_solve'] !== null,
            isTeamOrDuo: $isTeamOrDuo,
            unboxed: $row['unboxed'],
            occurrenceIndex: $occurrence,
            isBackfill: false,
            speedPercentile: $speedPercentile,
            // Suppress weekly boost and daily warm-up — only the settled line matters.
            xpEarningSolvesThisWeek: XpCalculator::WEEKLY_BOOST_SOLVE_LIMIT,
            isFirstXpEarningSolveOfDay: false,
        ));

        foreach ($awards as $award) {
            if ($award->reason === $wantedReason) {
                return $award->amount;
            }
        }

        return 0;
    }

    /**
     * Awarded (player, solve) pairs on go-forward solves whose puzzle now has a bonus-worthy
     * difficulty tier but which have neither a live difficulty bonus nor a settlement yet.
     *
     * @phpstan-return list<SolveRow>
     */
    private function loadPendingDifficultySettlements(): array
    {
        $sql = self::SOLVE_SELECT_FROM_ENTRIES . <<<SQL

WHERE e.reason = :solveBase
  AND pst.suspicious = false
  AND pst.tracked_at >= CAST(:cutoff AS TIMESTAMP)
  AND pd.difficulty_tier >= 3
  AND NOT EXISTS (
    SELECT 1 FROM xp_entry d
    WHERE d.player_id = e.player_id
      AND d.solving_time_id = e.solving_time_id
      AND d.reason IN (:difficultyReasons)
  )
SQL;

        /** @phpstan-var list<SolveRow> $rows */
        $rows = $this->database->executeQuery(
            $sql,
            [
                'solveBase' => XpReason::SolveBase->value,
                'cutoff' => XpCalculator::FULL_FORMULA_FROM,
                'difficultyReasons' => [XpReason::SolveDifficultyBonus->value, XpReason::DifficultySettlement->value],
            ],
            ['difficultyReasons' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return $rows;
    }

    /**
     * @phpstan-return list<SolveRow>
     */
    private function loadPendingSpeedSettlements(): array
    {
        $sql = self::SOLVE_SELECT_FROM_ENTRIES . <<<SQL

WHERE e.reason = :solveBase
  AND pst.suspicious = false
  AND pst.tracked_at >= CAST(:cutoff AS TIMESTAMP)
  AND pst.puzzling_type = 'solo'
  AND pst.seconds_to_solve IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM xp_entry d
    WHERE d.player_id = e.player_id
      AND d.solving_time_id = e.solving_time_id
      AND d.reason IN (:speedReasons)
  )
SQL;

        /** @phpstan-var list<SolveRow> $rows */
        $rows = $this->database->executeQuery(
            $sql,
            [
                'solveBase' => XpReason::SolveBase->value,
                'cutoff' => XpCalculator::FULL_FORMULA_FROM,
                'speedReasons' => [XpReason::SolveSpeedBonus->value, XpReason::SpeedSettlement->value],
            ],
            ['speedReasons' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return $rows;
    }

    private function compensateEntries(string $playerId, string $solvingTimeId): void
    {
        /** @var list<array{amount: int, reason: string, earned_at: string, in_weekly_delta: bool}> $entries */
        $entries = $this->database->fetchAllAssociative(
            'SELECT amount, reason, earned_at, in_weekly_delta FROM xp_entry
             WHERE player_id = :playerId AND solving_time_id = :solvingTimeId',
            ['playerId' => $playerId, 'solvingTimeId' => $solvingTimeId],
        );

        $net = 0;
        foreach ($entries as $entry) {
            $net += $entry['amount'];
        }

        // Net zero = already compensated (message redelivery) or never earned anything.
        if ($net === 0) {
            return;
        }

        try {
            $player = $this->playerRepository->get($playerId);
        } catch (PlayerNotFound) {
            return;
        }

        $drafts = [];
        foreach ($entries as $entry) {
            if ($entry['reason'] === XpReason::SolveCompensation->value) {
                continue;
            }

            $drafts[] = new XpEntryDraft(
                reason: XpReason::SolveCompensation,
                amount: -$entry['amount'],
                earnedAt: new DateTimeImmutable($entry['earned_at']),
                inWeeklyDelta: $entry['in_weekly_delta'],
                solvingTimeId: Uuid::fromString($solvingTimeId),
            );
        }

        $this->xpLedger->append($player, $drafts);
    }

    /**
     * Wipe and deterministically replay every entry of the (participant, puzzle) pair.
     * $extraSolveIds covers orphaned entries of solves the participant is no longer part
     * of (e.g. removed from the team on edit). Compensation lines and entries of deleted
     * solves are never touched — they are the audit history.
     *
     * @param list<string> $extraSolveIds
     */
    private function rebuildPair(string $playerId, string $puzzleId, array $extraSolveIds): void
    {
        try {
            $player = $this->playerRepository->get($playerId);
        } catch (PlayerNotFound) {
            return;
        }

        $solves = $this->loadPairSolves($playerId, $puzzleId);

        $solveIds = array_values(array_unique([...array_column($solves, 'id'), ...$extraSolveIds]));

        $wipedSum = 0;

        if ($solveIds !== []) {
            $wiped = $this->database->fetchOne(
                'SELECT COALESCE(SUM(amount), 0) FROM xp_entry
                 WHERE player_id = :playerId AND solving_time_id IN (:solvingTimeIds) AND reason != :compensation',
                [
                    'playerId' => $playerId,
                    'solvingTimeIds' => $solveIds,
                    'compensation' => XpReason::SolveCompensation->value,
                ],
                ['solvingTimeIds' => ArrayParameterType::STRING],
            );
            $wipedSum = is_numeric($wiped) ? (int) $wiped : 0;

            $this->database->executeStatement(
                'DELETE FROM xp_entry
                 WHERE player_id = :playerId AND solving_time_id IN (:solvingTimeIds) AND reason != :compensation',
                [
                    'playerId' => $playerId,
                    'solvingTimeIds' => $solveIds,
                    'compensation' => XpReason::SolveCompensation->value,
                ],
                ['solvingTimeIds' => ArrayParameterType::STRING],
            );
        }

        $drafts = [];
        $occurrence = 0;

        /** @var array<string, int> $weeklyReplayed */
        $weeklyReplayed = [];
        /** @var array<string, true> $dailyReplayed */
        $dailyReplayed = [];

        foreach ($solves as $solve) {
            $occurrence++;
            $earnedAt = new DateTimeImmutable($solve['earned_at']);
            $weekKey = $earnedAt->format('o-\WW');
            $dayKey = $earnedAt->format('Y-m-d');

            $awards = $this->xpCalculator->calculate($this->contextFor(
                solve: $solve,
                participantId: $playerId,
                occurrence: $occurrence,
                earnedAt: $earnedAt,
                weeklyExtra: $weeklyReplayed[$weekKey] ?? 0,
                dayAlreadyReplayed: isset($dailyReplayed[$dayKey]),
            ));

            if ($awards === []) {
                continue;
            }

            $weeklyReplayed[$weekKey] = ($weeklyReplayed[$weekKey] ?? 0) + 1;
            $dailyReplayed[$dayKey] = true;

            foreach ($awards as $award) {
                $drafts[] = new XpEntryDraft(
                    reason: $award->reason,
                    amount: $award->amount,
                    earnedAt: $earnedAt,
                    inWeeklyDelta: true,
                    solvingTimeId: Uuid::fromString($solve['id']),
                );
            }
        }

        // The wipe above bypassed the ledger, so subtract the wiped sum from the
        // denormalized totals incrementally — a fresh DB SUM would miss unflushed entries
        // (e.g. compensations appended moments ago in the deletion flow).
        $adjustedTotal = $player->xpTotal - $wipedSum;
        $player->updateExperience($adjustedTotal, LevelTable::levelForXp($adjustedTotal));

        $this->xpLedger->append($player, $drafts);
    }

    /**
     * @phpstan-param SolveRow $solve
     */
    private function contextFor(
        array $solve,
        string $participantId,
        int $occurrence,
        DateTimeImmutable $earnedAt,
        int $weeklyExtra = 0,
        bool $dayAlreadyReplayed = false,
    ): SolveXpContext {
        $isBackfill = new DateTimeImmutable($solve['tracked_at']) < XpCalculator::fullFormulaFrom();
        $isTimed = $solve['seconds_to_solve'] !== null;
        $isTeamOrDuo = $solve['puzzling_type'] !== 'solo';

        $speedPercentile = SpeedPercentile::None;

        if ($isBackfill === false && $isTeamOrDuo === false && $solve['seconds_to_solve'] !== null) {
            $speedPercentile = $this->resolveSpeedPercentile($solve, $participantId);
        }

        return new SolveXpContext(
            piecesCount: $solve['pieces'],
            difficultyTier: $solve['difficulty_tier'],
            isTimed: $isTimed,
            isTeamOrDuo: $isTeamOrDuo,
            unboxed: $solve['unboxed'],
            occurrenceIndex: $occurrence,
            isBackfill: $isBackfill,
            speedPercentile: $speedPercentile,
            xpEarningSolvesThisWeek: $weeklyExtra + $this->countWeekSolvesBefore($participantId, $earnedAt, $solve['id']),
            isFirstXpEarningSolveOfDay: $dayAlreadyReplayed === false
                && $this->hasDaySolveBefore($participantId, $earnedAt, $solve['id']) === false,
        );
    }

    /**
     * @phpstan-param SolveRow $solve
     */
    private function resolveSpeedPercentile(array $solve, string $participantId): SpeedPercentile
    {
        $seconds = $solve['seconds_to_solve'];

        if ($seconds === null) {
            return SpeedPercentile::None;
        }

        if (XpCalculator::isImplausiblyFast($solve['pieces'], $seconds)) {
            // Silent guard: no speed bonus, no user-facing accusation — ops visibility only.
            $this->logger->warning('XP speed bonus denied by plausibility guard', [
                'solvingTimeId' => $solve['id'],
                'playerId' => $participantId,
                'piecesCount' => $solve['pieces'],
                'secondsToSolve' => $seconds,
            ]);

            return SpeedPercentile::None;
        }

        return $this->getPuzzleSpeedPercentiles
            ->forPuzzle($solve['puzzle_id'])
            ->percentileFor($seconds);
    }

    /**
     * @phpstan-return SolveRow|null
     */
    private function loadSolve(string $solvingTimeId): null|array
    {
        /** @phpstan-var SolveRow|false $row */
        $row = $this->database
            ->executeQuery(self::SOLVE_SELECT . ' WHERE pst.id = :id', ['id' => $solvingTimeId])
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * @phpstan-return list<SolveRow>
     */
    private function loadPairSolves(string $playerId, string $puzzleId): array
    {
        $sql = self::SOLVE_SELECT . <<<SQL

WHERE pst.suspicious = false
  AND pst.puzzle_id = :puzzleId
  AND (
    pst.player_id = :playerId
    OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
  )
ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.id
SQL;

        /** @phpstan-var list<SolveRow> $rows */
        $rows = $this->database
            ->executeQuery($sql, ['playerId' => $playerId, 'puzzleId' => $puzzleId])
            ->fetchAllAssociative();

        return $rows;
    }

    /**
     * Registered participants: the owner plus every team member with a player id.
     *
     * @phpstan-param SolveRow $solve
     * @return list<string>
     */
    private function participantsOf(array $solve): array
    {
        $participants = [$solve['player_id']];

        if ($solve['team'] !== null) {
            try {
                $team = Json::decode($solve['team'], forceArrays: true);
            } catch (JsonException) {
                $team = null;
            }

            if (is_array($team) && isset($team['puzzlers']) && is_array($team['puzzlers'])) {
                foreach ($team['puzzlers'] as $puzzler) {
                    if (is_array($puzzler) && isset($puzzler['player_id']) && is_string($puzzler['player_id'])) {
                        $participants[] = $puzzler['player_id'];
                    }
                }
            }
        }

        return array_values(array_unique($participants));
    }

    /**
     * @return list<string>
     */
    private function playersWithEntriesFor(string $solvingTimeId): array
    {
        /** @var list<string> $ids */
        $ids = $this->database
            ->executeQuery(
                'SELECT DISTINCT player_id FROM xp_entry WHERE solving_time_id = :solvingTimeId',
                ['solvingTimeId' => $solvingTimeId],
            )
            ->fetchFirstColumn();

        return $ids;
    }

    private function hasAnyEntry(string $playerId, string $solvingTimeId): bool
    {
        $value = $this->database->fetchOne(
            'SELECT 1 FROM xp_entry WHERE player_id = :playerId AND solving_time_id = :solvingTimeId LIMIT 1',
            ['playerId' => $playerId, 'solvingTimeId' => $solvingTimeId],
        );

        return $value !== false;
    }

    /**
     * 1-based canonical position of this solve among ALL of the participant's solves
     * of the puzzle (both modes).
     */
    private function occurrenceIndex(string $playerId, string $puzzleId, DateTimeImmutable $earnedAt, string $solvingTimeId): int
    {
        $sql = <<<SQL
SELECT COUNT(*)
FROM puzzle_solving_time pst
WHERE pst.suspicious = false
  AND pst.puzzle_id = :puzzleId
  AND (
    pst.player_id = :playerId
    OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
  )
  AND (COALESCE(pst.finished_at, pst.tracked_at), pst.id) < (CAST(:earnedAt AS TIMESTAMP), CAST(:solvingTimeId AS UUID))
SQL;

        $value = $this->database->executeQuery($sql, [
            'playerId' => $playerId,
            'puzzleId' => $puzzleId,
            'earnedAt' => $earnedAt->format('Y-m-d H:i:s'),
            'solvingTimeId' => $solvingTimeId,
        ])->fetchOne();

        return (is_numeric($value) ? (int) $value : 0) + 1;
    }

    /**
     * XP-earning solves (= solve_base entries of still-existing solves) canonically before
     * this solve within its ISO week.
     */
    private function countWeekSolvesBefore(string $playerId, DateTimeImmutable $earnedAt, string $solvingTimeId): int
    {
        $weekStart = $earnedAt
            ->setISODate((int) $earnedAt->format('o'), (int) $earnedAt->format('W'))
            ->setTime(0, 0);

        $sql = <<<SQL
SELECT COUNT(*)
FROM xp_entry e
WHERE e.player_id = :playerId
  AND e.reason = :reason
  AND e.earned_at >= CAST(:windowStart AS TIMESTAMP)
  AND e.earned_at < CAST(:windowEnd AS TIMESTAMP)
  AND (e.earned_at, e.solving_time_id) < (CAST(:earnedAt AS TIMESTAMP), CAST(:solvingTimeId AS UUID))
  AND EXISTS (SELECT 1 FROM puzzle_solving_time pst WHERE pst.id = e.solving_time_id)
SQL;

        $value = $this->database->executeQuery($sql, [
            'playerId' => $playerId,
            'reason' => XpReason::SolveBase->value,
            'windowStart' => $weekStart->format('Y-m-d H:i:s'),
            'windowEnd' => $weekStart->modify('+7 days')->format('Y-m-d H:i:s'),
            'earnedAt' => $earnedAt->format('Y-m-d H:i:s'),
            'solvingTimeId' => $solvingTimeId,
        ])->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    private function hasDaySolveBefore(string $playerId, DateTimeImmutable $earnedAt, string $solvingTimeId): bool
    {
        $dayStart = $earnedAt->setTime(0, 0);

        $sql = <<<SQL
SELECT 1
FROM xp_entry e
WHERE e.player_id = :playerId
  AND e.reason = :reason
  AND e.earned_at >= CAST(:windowStart AS TIMESTAMP)
  AND e.earned_at < CAST(:windowEnd AS TIMESTAMP)
  AND (e.earned_at, e.solving_time_id) < (CAST(:earnedAt AS TIMESTAMP), CAST(:solvingTimeId AS UUID))
  AND EXISTS (SELECT 1 FROM puzzle_solving_time pst WHERE pst.id = e.solving_time_id)
LIMIT 1
SQL;

        $value = $this->database->executeQuery($sql, [
            'playerId' => $playerId,
            'reason' => XpReason::SolveBase->value,
            'windowStart' => $dayStart->format('Y-m-d H:i:s'),
            'windowEnd' => $dayStart->modify('+1 day')->format('Y-m-d H:i:s'),
            'earnedAt' => $earnedAt->format('Y-m-d H:i:s'),
            'solvingTimeId' => $solvingTimeId,
        ])->fetchOne();

        return $value !== false;
    }
}
