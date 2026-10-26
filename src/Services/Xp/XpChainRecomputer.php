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
use SpeedPuzzling\Web\Results\PuzzleSpeedPercentiles;
use SpeedPuzzling\Web\Services\Progression\PlayerProgressionLock;
use SpeedPuzzling\Web\Value\SolveXpContext;
use SpeedPuzzling\Web\Value\SpeedPercentile;
use SpeedPuzzling\Web\Value\XpEntryDraft;
use SpeedPuzzling\Web\Value\XpReason;

/**
 * Live XP wiring for the solve lifecycle - add (award), edit (rebuild), delete (compensate + rebuild), plus the
 * ex-post settlements. Each one rebuilds what the change can reach (rebuildScope()) from the one replay a full
 * rebuild uses too (XpHistoryReplay), so after any sequence of adds, edits, deletions and re-adds the ledger equals a
 * fresh XpRecomputer run on the current records - repeating an action earns nothing (README §Anti-farming).
 *
 * A deleted solve's entries stay as audit history with exact negative mirrors (solve_compensation).
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
        private XpHistoryReplay $xpHistoryReplay,
        private PlayerProgressionLock $playerProgressionLock,
        private GetPuzzleSpeedPercentiles $getPuzzleSpeedPercentiles,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private XpFormulaCutoff $xpFormulaCutoff,
    ) {
    }

    /**
     * A new solve (also one that comes back with an id it had before - Undo of a removal, a resent form): every
     * registered participant's ledger is brought to what a full replay writes. Idempotent under redelivery.
     */
    public function awardForNewSolve(string $solvingTimeId): void
    {
        $solve = $this->loadSolve($solvingTimeId);

        if ($solve === null) {
            return;
        }

        $participants = $this->participantsOf($solve);
        $this->playerProgressionLock->lock($participants);

        foreach ($participants as $participantId) {
            $this->rebuildScope($participantId, [$solve['puzzle_id']], [$solvingTimeId]);
        }
    }

    /**
     * An edited solve (time, date, mode, group, the suspicious flag set or cleared, a merge moving it to another
     * puzzle): the edit is semantically delete + re-add - every participant now, everybody who earned for it before
     * and $extraPlayerIds (members before the edit, who may have earned nothing for it) is rebuilt.
     *
     * @param list<string> $extraPlayerIds
     */
    public function rebuildChainForEditedSolve(string $solvingTimeId, array $extraPlayerIds = []): void
    {
        $solve = $this->loadSolve($solvingTimeId);

        if ($solve === null) {
            // Solve got deleted before this message ran — the deletion flow owns cleanup.
            return;
        }

        $participants = array_values(array_unique([
            ...$this->participantsOf($solve),
            ...$this->playersWithEntriesFor($solvingTimeId),
            ...$extraPlayerIds,
        ]));
        $this->playerProgressionLock->lock($participants);

        foreach ($participants as $participantId) {
            $this->rebuildScope($participantId, [$solve['puzzle_id']], [$solvingTimeId]);
        }
    }

    /**
     * A deleted solve: its entries stay as the audit history and get exact negative mirrors, then everything the
     * deletion reaches is rebuilt (later solves of the puzzle move up, its week's boost goes to the
     * next solve). $memberPlayerIds = its registered members, who may have earned nothing for it (a relax repeat)
     * and still have later solves to move up.
     *
     * @param list<string> $memberPlayerIds
     */
    public function compensateAndRebuildAfterDeletion(string $solvingTimeId, string $puzzleId, array $memberPlayerIds = []): void
    {
        $affectedPlayers = array_values(array_unique([
            ...$this->playersWithEntriesFor($solvingTimeId),
            ...$memberPlayerIds,
        ]));
        $this->playerProgressionLock->lock($affectedPlayers);

        foreach ($affectedPlayers as $participantId) {
            $this->compensateEntries($participantId, $solvingTimeId);
            $this->rebuildScope($participantId, [$puzzleId], [$solvingTimeId]);
        }
    }

    /**
     * Ex-post settlements (§1.4): a solve logged on an unrated puzzle earns its difficulty
     * bonus once the puzzle first gets a difficulty tier; a solve on a puzzle without a
     * reliable median earns its speed bonus once ≥3 distinct solvers exist. Settled once
     * (anchored by the unique (player, solve, reason) index) — later tier or median drift
     * revises nothing until a change of the player's results replays the solve. Go-forward
     * solves only; settlements never count toward the weekly delta and carry the settlement
     * run time as earned_at.
     *
     * Two steps (SettleXpBonusesHandler): this one, without any lock or transaction, finds who gets something paid;
     * then settlePendingBonusesOf() settles each of them in a transaction of its own. Most pending rows settle nothing
     * (a solve slower than the median stays pending forever - 9,665 rows of 1,933 players, none of them payable,
     * 2026-10-26); locking all of their players in one transaction kept every insert referencing them waiting ~15 s.
     * A player whose bonus becomes payable only after this read is settled by the next run.
     *
     * @return list<string>
     */
    public function playersWithPendingSettlements(): array
    {
        return array_keys($this->pendingSettlements($this->clock->now(), null));
    }

    /**
     * Settles the player's pending bonuses - in the caller's transaction, which commits them (one per player: a run
     * that stops midway leaves the rest pending for the next run, nothing half-written). The player is locked first
     * and the pending rows read only then: a rebuild that committed meanwhile may have written the bonus itself, and
     * settling it on top would pay it twice.
     *
     * Returns the number of settlement entries created.
     */
    public function settlePendingBonusesOf(string $playerId): int
    {
        $this->playerProgressionLock->lock([$playerId]);

        $settlement = $this->pendingSettlements($this->clock->now(), [$playerId])[$playerId] ?? null;

        if ($settlement === null) {
            return 0;
        }

        try {
            $player = $this->playerRepository->get($playerId);
        } catch (PlayerNotFound) {
            return 0;
        }

        $this->xpLedger->append($player, $settlement['drafts']);
        $this->xpLedger->recordAwardedTiers($playerId, $settlement['tiers']);

        return count($settlement['drafts']);
    }

    /**
     * The settlement entries pending now, of every player or of $playerIds only - players with nothing to be paid are
     * left out. Reads only, locks nothing.
     *
     * @param null|list<string> $playerIds
     * @return array<string, array{drafts: non-empty-list<XpEntryDraft>, tiers: list<array{solvingTimeId: string, puzzleId: string, difficultyTier: null|int}>}>
     */
    private function pendingSettlements(DateTimeImmutable $now, null|array $playerIds): array
    {
        /** @var array<string, list<XpEntryDraft>> $draftsByPlayer */
        $draftsByPlayer = [];
        /** @var array<string, list<array{solvingTimeId: string, puzzleId: string, difficultyTier: null|int}>> $settledTiersByPlayer */
        $settledTiersByPlayer = [];

        foreach ($this->loadPendingDifficultySettlements($playerIds) as $row) {
            $amount = $this->settlementAmount($row, XpReason::SolveDifficultyBonus);

            if ($amount <= 0) {
                continue;
            }

            $settledTiersByPlayer[$row['player_id']][] = [
                'solvingTimeId' => $row['id'],
                'puzzleId' => $row['puzzle_id'],
                'difficultyTier' => $row['difficulty_tier'],
            ];

            $draftsByPlayer[$row['player_id']][] = new XpEntryDraft(
                reason: XpReason::DifficultySettlement,
                amount: $amount,
                earnedAt: $now,
                inWeeklyDelta: false,
                solvingTimeId: Uuid::fromString($row['id']),
            );
        }

        $speedRows = $this->loadPendingSpeedSettlements($playerIds);
        $percentilesByPuzzle = [];

        // One query per 1,000 puzzles instead of two per pending row (~15 s for the 9,665 rows of 2026-10-26)
        foreach (array_chunk(array_values(array_unique(array_column($speedRows, 'puzzle_id'))), 1000) as $puzzleIds) {
            $percentilesByPuzzle += $this->getPuzzleSpeedPercentiles->forPuzzles($puzzleIds);
        }

        foreach ($speedRows as $row) {
            $speedPercentile = $this->resolveSpeedPercentile(
                $row,
                $row['player_id'],
                $percentilesByPuzzle[$row['puzzle_id']] ?? PuzzleSpeedPercentiles::empty(),
            );

            // No speed bonus at this percentile, whatever the rest of the receipt - skips the occurrence query
            if ($speedPercentile->bonusRate() <= 0.0) {
                continue;
            }

            $amount = $this->settlementAmount($row, XpReason::SolveSpeedBonus, $speedPercentile);

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
        }

        $settlements = [];

        foreach ($draftsByPlayer as $playerId => $drafts) {
            $settlements[$playerId] = [
                'drafts' => $drafts,
                'tiers' => $settledTiersByPlayer[$playerId] ?? [],
            ];
        }

        return $settlements;
    }

    /**
     * Runs the full calculator for the solve as it stands today and extracts the single
     * receipt line being settled — guarantees settlement amounts use the exact same math
     * and rounding as live awards.
     *
     * @phpstan-param SolveRow $row
     */
    private function settlementAmount(array $row, XpReason $wantedReason, SpeedPercentile $speedPercentile = SpeedPercentile::None): int
    {
        $earnedAt = new DateTimeImmutable($row['earned_at']);
        $occurrence = $this->occurrenceIndex($row['player_id'], $row['puzzle_id'], $earnedAt, $row['id']);

        $isTeamOrDuo = $row['puzzling_type'] !== 'solo';

        $awards = $this->xpCalculator->calculate(new SolveXpContext(
            piecesCount: $row['pieces'],
            difficultyTier: $row['difficulty_tier'],
            isTimed: $row['seconds_to_solve'] !== null,
            isTeamOrDuo: $isTeamOrDuo,
            unboxed: $row['unboxed'],
            occurrenceIndex: $occurrence,
            isBackfill: false,
            speedPercentile: $speedPercentile,
            // Suppress the weekly boost — only the settled line matters.
            xpEarningSolvesThisWeek: XpCalculator::WEEKLY_BOOST_SOLVE_LIMIT,
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
     * difficulty tier but which have neither a live difficulty bonus nor a settlement yet - and no frozen tier: a solve
     * that earned with a rated puzzle keeps that tier, settling is for puzzles unrated at the award.
     *
     * @param null|list<string> $playerIds null = every player
     * @phpstan-return list<SolveRow>
     */
    private function loadPendingDifficultySettlements(null|array $playerIds): array
    {
        $sql = self::SOLVE_SELECT_FROM_ENTRIES . <<<SQL

WHERE e.reason = :solveBase
  AND pst.suspicious = false
  AND pst.tracked_at >= CAST(:cutoff AS TIMESTAMP)
  AND pd.difficulty_tier >= 3
  AND NOT EXISTS (
    SELECT 1 FROM xp_solve_tier xst
    WHERE xst.player_id = e.player_id AND xst.solving_time_id = e.solving_time_id AND xst.puzzle_id = pst.puzzle_id
  )
  AND NOT EXISTS (
    SELECT 1 FROM xp_entry d
    WHERE d.player_id = e.player_id
      AND d.solving_time_id = e.solving_time_id
      AND d.reason IN (:difficultyReasons)
  )
SQL;

        $parameters = [];
        $types = ['difficultyReasons' => ArrayParameterType::STRING];

        if ($playerIds !== null) {
            $sql .= ' AND e.player_id IN (:playerIds)';
            $parameters['playerIds'] = $playerIds;
            $types['playerIds'] = ArrayParameterType::STRING;
        }

        /** @phpstan-var list<SolveRow> $rows */
        $rows = $this->database->executeQuery(
            $sql,
            [
                'solveBase' => XpReason::SolveBase->value,
                'cutoff' => $this->xpFormulaCutoff->fullFormulaFrom()->format('Y-m-d H:i:s'),
                'difficultyReasons' => [XpReason::SolveDifficultyBonus->value, XpReason::DifficultySettlement->value],
                ...$parameters,
            ],
            $types,
        )->fetchAllAssociative();

        return $rows;
    }

    /**
     * @param null|list<string> $playerIds null = every player
     * @phpstan-return list<SolveRow>
     */
    private function loadPendingSpeedSettlements(null|array $playerIds): array
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

        $parameters = [];
        $types = ['speedReasons' => ArrayParameterType::STRING];

        if ($playerIds !== null) {
            $sql .= ' AND e.player_id IN (:playerIds)';
            $parameters['playerIds'] = $playerIds;
            $types['playerIds'] = ArrayParameterType::STRING;
        }

        /** @phpstan-var list<SolveRow> $rows */
        $rows = $this->database->executeQuery(
            $sql,
            [
                'solveBase' => XpReason::SolveBase->value,
                'cutoff' => $this->xpFormulaCutoff->fullFormulaFrom()->format('Y-m-d H:i:s'),
                'speedReasons' => [XpReason::SolveSpeedBonus->value, XpReason::SpeedSettlement->value],
                ...$parameters,
            ],
            $types,
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
     * Brings the player's ledger to what a full replay (XpHistoryReplay, the same one XpRecomputer writes) gives for
     * every solve a change of $puzzleIds / $touchedSolveIds can reach:
     *
     *  - the player's whole chain on those puzzles (occurrence: first, repeat, relax repeat),
     *  - every solve in the ISO weeks those solves are in now or were in before (their old entries' earned_at) - the
     *    weekly boost belongs to the first XP-earning solves of the week, so a solve logged
     *    into the past, deleted or moved hands them over to the right solves instead of adding more (that is why
     *    back-dating, or deleting and re-adding, can never create extra slots),
     *  - the touched solves themselves, also when the player no longer earns for them (suspicious now, left the
     *    group): their entries go.
     *
     * Only solves whose entries differ from the replay are rewritten (a settlement counts as the bonus it settled),
     * so the XP history of untouched solves keeps its dates. A solve of another puzzle that is in the scope only for its
     * week (same time, same base line) keeps every line it earned and only gains or loses its weekly boost: the replay
     * reads today's difficulty tiers and speed medians, and a puzzle logged from the backlog took the difficulty
     * bonus of every solve of its week after the tiers moved (found 2026-10-23). A solve that exists drops all its
     * old entries - also the compensation of an earlier deletion when its id came back (Undo, a resent form) - and a
     * deleted solve keeps its entries and their compensation as the audit history.
     *
     * The caller holds PlayerProgressionLock for the player.
     *
     * @param list<string> $puzzleIds
     * @param list<string> $touchedSolveIds
     */
    private function rebuildScope(string $playerId, array $puzzleIds, array $touchedSolveIds): void
    {
        try {
            $player = $this->playerRepository->get($playerId);
        } catch (PlayerNotFound) {
            return;
        }

        $history = $this->xpHistoryReplay->loadHistory($playerId);
        $puzzleSet = array_fill_keys($puzzleIds, true);

        /** @var array<string, true> $weeks */
        $weeks = [];
        /** @var array<string, true> $positionedSolveIds */
        $positionedSolveIds = array_fill_keys($touchedSolveIds, true);

        foreach ($history as $solve) {
            if (isset($puzzleSet[$solve['puzzle_id']])) {
                $positionedSolveIds[$solve['id']] = true;
                $weeks[XpHistoryReplay::weekKey(new DateTimeImmutable($solve['earned_at']))] = true;
            }
        }

        foreach ($this->previousPositions($playerId, array_keys($positionedSolveIds)) as $earnedAt) {
            $weeks[XpHistoryReplay::weekKey(new DateTimeImmutable($earnedAt))] = true;
        }

        /** @var array<string, true> $scope */
        $scope = [];

        /** @var array<string, true> $weekMates solves in the scope only for their week's boost */
        $weekMates = [];

        foreach ($history as $solve) {
            if (isset($puzzleSet[$solve['puzzle_id']])) {
                $scope[$solve['id']] = true;
            } elseif (isset($weeks[XpHistoryReplay::weekKey(new DateTimeImmutable($solve['earned_at']))])) {
                $scope[$solve['id']] = true;
                $weekMates[$solve['id']] = true;
            }
        }

        $targets = $this->xpHistoryReplay->replay($playerId, $history, $scope);

        $touchedOutsideScope = array_values(array_filter(
            $touchedSolveIds,
            static fn (string $solveId): bool => isset($scope[$solveId]) === false,
        ));
        $stillExisting = array_fill_keys($this->existingSolveIds($touchedOutsideScope), true);

        $candidateIds = array_values(array_unique([...array_keys($scope), ...$touchedOutsideScope]));
        $currentEntries = $this->entriesBySolve($playerId, $candidateIds);

        $entryIdsToRemove = [];
        $drafts = [];
        /** @var list<array{solvingTimeId: string, puzzleId: string, difficultyTier: null|int}> $earnedWithTier */
        $earnedWithTier = [];
        $historyById = array_column($history, null, 'id');

        foreach ($candidateIds as $solveId) {
            if (isset($scope[$solveId]) === false && isset($stillExisting[$solveId]) === false) {
                // Deleted - the compensation flow owns its history
                continue;
            }

            $target = $targets[$solveId] ?? [];
            $current = $currentEntries[$solveId] ?? [];

            if (isset($weekMates[$solveId]) && self::earnedInTheSamePlace($current, $target)) {
                // A solve of another puzzle keeps what it earned - difficulty tiers and speed medians drift with the
                // community, and logging a puzzle from the backlog must never take XP from the solves of its week.
                // Only the weekly boost can go from one of them to another.
                $current = array_values(array_filter(
                    $current,
                    static fn (array $entry): bool => $entry['reason'] === XpReason::SolveWeeklyBoost->value,
                ));
                $target = array_values(array_filter(
                    $target,
                    static fn (XpEntryDraft $draft): bool => $draft->reason === XpReason::SolveWeeklyBoost,
                ));

                if ($current !== [] && $target !== []) {
                    // Still one of the week's first solves - the boost it got stays too
                    continue;
                }
            } elseif ($target !== [] && isset($historyById[$solveId])) {
                // Replayed in full: from now on it keeps the tier it earned with
                $earnedWithTier[] = [
                    'solvingTimeId' => $solveId,
                    'puzzleId' => $historyById[$solveId]['puzzle_id'],
                    'difficultyTier' => $historyById[$solveId]['difficulty_tier'],
                ];
            }

            if (self::matches($current, $target)) {
                continue;
            }

            foreach ($current as $entry) {
                $entryIdsToRemove[] = $entry['id'];
            }

            foreach ($target as $draft) {
                $drafts[] = $draft;
            }
        }

        $this->xpLedger->recordAwardedTiers($playerId, $earnedWithTier);

        if ($entryIdsToRemove === [] && $drafts === []) {
            return;
        }

        $this->xpLedger->rewrite($player, $entryIdsToRemove, $drafts);
    }

    /**
     * Where the solves sat when their entries were written (solve-derived lines carry the solve's time; settlements
     * the settlement run, compensations the deleted solve's lines - neither says more).
     *
     * @param list<string> $solveIds
     * @return list<string> earned_at values
     */
    private function previousPositions(string $playerId, array $solveIds): array
    {
        if ($solveIds === []) {
            return [];
        }

        /** @var list<string> $earnedAt */
        $earnedAt = $this->database->executeQuery(
            'SELECT DISTINCT earned_at FROM xp_entry
             WHERE player_id = :playerId AND solving_time_id IN (:solveIds) AND reason NOT IN (:notPositional)',
            [
                'playerId' => $playerId,
                'solveIds' => $solveIds,
                'notPositional' => [
                    XpReason::SolveCompensation->value,
                    XpReason::DifficultySettlement->value,
                    XpReason::SpeedSettlement->value,
                ],
            ],
            ['solveIds' => ArrayParameterType::STRING, 'notPositional' => ArrayParameterType::STRING],
        )->fetchFirstColumn();

        return $earnedAt;
    }

    /**
     * @param list<string> $solveIds
     * @return list<string>
     */
    private function existingSolveIds(array $solveIds): array
    {
        if ($solveIds === []) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = $this->database->executeQuery(
            'SELECT id FROM puzzle_solving_time WHERE id IN (:solveIds)',
            ['solveIds' => $solveIds],
            ['solveIds' => ArrayParameterType::STRING],
        )->fetchFirstColumn();

        return $ids;
    }

    /**
     * @param list<string> $solveIds
     * @return array<string, list<array{id: string, reason: string, amount: int, earned_at: string}>>
     */
    private function entriesBySolve(string $playerId, array $solveIds): array
    {
        if ($solveIds === []) {
            return [];
        }

        /** @var list<array{id: string, solving_time_id: string, reason: string, amount: int, earned_at: string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT id, solving_time_id, reason, amount, earned_at FROM xp_entry
             WHERE player_id = :playerId AND solving_time_id IN (:solveIds)',
            ['playerId' => $playerId, 'solveIds' => $solveIds],
            ['solveIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $bySolve = [];

        foreach ($rows as $row) {
            $bySolve[$row['solving_time_id']][] = [
                'id' => $row['id'],
                'reason' => $row['reason'],
                'amount' => $row['amount'],
                'earned_at' => $row['earned_at'],
            ];
        }

        return $bySolve;
    }

    /**
     * Whether a solve still sits where its entries were written: the same time, the same base line (occurrence, mode
     * and pieces unchanged) and no compensation - then its difficulty, unboxed and speed lines stay as they were.
     *
     * @param list<array{id: string, reason: string, amount: int, earned_at: string}> $current
     * @param list<XpEntryDraft> $target
     */
    private static function earnedInTheSamePlace(array $current, array $target): bool
    {
        $targetBase = null;

        foreach ($target as $draft) {
            if ($draft->reason === XpReason::SolveBase) {
                $targetBase = $draft;
            }
        }

        if ($targetBase === null) {
            return false;
        }

        $currentBase = null;

        foreach ($current as $entry) {
            if ($entry['reason'] === XpReason::SolveCompensation->value) {
                return false;
            }

            if ($entry['reason'] === XpReason::SolveBase->value) {
                $currentBase = $entry;
            }
        }

        return $currentBase !== null
            && $currentBase['amount'] === $targetBase->amount
            && $currentBase['earned_at'] === $targetBase->earnedAt->format('Y-m-d H:i:s');
    }

    /**
     * Whether a solve's entries already say what the replay says: the same amount per receipt line, at the solve's
     * time. A settlement counts as the bonus it settled (same amount, written later); a compensation line never
     * belongs to a solve that exists.
     *
     * @param list<array{id: string, reason: string, amount: int, earned_at: string}> $current
     * @param list<XpEntryDraft> $target
     */
    private static function matches(array $current, array $target): bool
    {
        $wanted = [];
        $earnedAt = null;

        foreach ($target as $draft) {
            $wanted[$draft->reason->value] = ($wanted[$draft->reason->value] ?? 0) + $draft->amount;
            $earnedAt = $draft->earnedAt->format('Y-m-d H:i:s');
        }

        $have = [];

        foreach ($current as $entry) {
            $reason = match ($entry['reason']) {
                XpReason::SolveCompensation->value => null,
                XpReason::DifficultySettlement->value => XpReason::SolveDifficultyBonus->value,
                XpReason::SpeedSettlement->value => XpReason::SolveSpeedBonus->value,
                default => $entry['reason'],
            };

            if ($reason === null) {
                return false;
            }

            $isSettlement = $reason !== $entry['reason'];

            if ($isSettlement === false && $entry['earned_at'] !== $earnedAt) {
                return false;
            }

            $have[$reason] = ($have[$reason] ?? 0) + $entry['amount'];
        }

        ksort($wanted);
        ksort($have);

        return $wanted === $have;
    }

    /**
     * @phpstan-param SolveRow $solve
     */
    private function resolveSpeedPercentile(array $solve, string $participantId, PuzzleSpeedPercentiles $puzzlePercentiles): SpeedPercentile
    {
        $seconds = $solve['seconds_to_solve'];

        if ($seconds === null) {
            return SpeedPercentile::None;
        }

        if (XpCalculator::isImplausiblyFast($solve['pieces'], $seconds)) {
            // Silent guard: no speed bonus, no user-facing accusation — ops visibility only. Info, not warning: a
            // replay of every implausible solve (rebuild, backfill) must not become Sentry issues
            $this->logger->info('XP speed bonus denied by plausibility guard', [
                'solvingTimeId' => $solve['id'],
                'playerId' => $participantId,
                'piecesCount' => $solve['pieces'],
                'secondsToSolve' => $seconds,
            ]);

            return SpeedPercentile::None;
        }

        return $puzzlePercentiles->percentileFor($seconds);
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
}
