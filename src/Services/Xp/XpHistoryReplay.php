<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetPuzzleSpeedPercentiles;
use SpeedPuzzling\Web\Results\PuzzleSpeedPercentiles;
use SpeedPuzzling\Web\Value\SolveXpContext;
use SpeedPuzzling\Web\Value\SpeedPercentile;
use SpeedPuzzling\Web\Value\XpAward;
use SpeedPuzzling\Web\Value\XpEntryDraft;
use SpeedPuzzling\Web\Value\XpReason;

/**
 * The one replay of a player's XP: their whole current solve history (registered participations, suspicious solves
 * left out) in canonical order (COALESCE(finished_at, tracked_at), id), occurrence per puzzle, the weekly boost
 * handed to the first XP-earning solves of the ISO week. A solve's difficulty is the tier it earned its XP with
 * (XpSolveTier) while it is on the same puzzle, today's tier only before it has one.
 *
 * XpRecomputer writes the drafts of every solve, XpChainRecomputer only those of the solves a change can reach -
 * both read them from here, so the live wiring and a full rebuild always write the same ledger: whatever the order
 * of adds, edits, deletions and re-adds, the XP equals a fresh replay of the current records (path independence,
 * docs/features/xp-levels/README.md §Anti-farming).
 *
 * @phpstan-type HistorySolve array{id: string, puzzle_id: string, seconds_to_solve: null|int, puzzling_type: string, unboxed: bool, pieces: int, difficulty_tier: null|int, tier_frozen: bool, tracked_at: string, earned_at: string}
 */
readonly final class XpHistoryReplay
{
    public function __construct(
        private Connection $database,
        private XpCalculator $xpCalculator,
        private GetPuzzleSpeedPercentiles $getPuzzleSpeedPercentiles,
        private LoggerInterface $logger,
        private XpFormulaCutoff $xpFormulaCutoff,
    ) {
    }

    /**
     * @phpstan-return list<HistorySolve>
     */
    public function loadHistory(string $playerId): array
    {
        $sql = <<<SQL
SELECT
    pst.id,
    pst.puzzle_id,
    pst.seconds_to_solve,
    pst.puzzling_type,
    pst.unboxed,
    COALESCE(pst.pieces_count_snapshot, p.pieces_count) AS pieces,
    COALESCE(xst.difficulty_tier, pd.difficulty_tier) AS difficulty_tier,
    xst.difficulty_tier IS NOT NULL AS tier_frozen,
    pst.tracked_at,
    COALESCE(pst.finished_at, pst.tracked_at) AS earned_at
FROM puzzle_solving_time pst
JOIN puzzle p ON p.id = pst.puzzle_id
LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = pst.puzzle_id
LEFT JOIN xp_solve_tier xst
    ON xst.player_id = :playerId AND xst.solving_time_id = pst.id AND xst.puzzle_id = pst.puzzle_id
WHERE pst.suspicious = false
  AND (
    pst.player_id = :playerId
    OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
  )
ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.id
SQL;

        /** @phpstan-var list<HistorySolve> $rows */
        $rows = $this->database->executeQuery($sql, ['playerId' => $playerId])->fetchAllAssociative();

        return $rows;
    }

    /**
     * Replays the whole history; drafts are built only for the solves in $scope (null = every solve). A solve outside
     * the scope still takes its occurrence and its weekly slot - that is what the solves in the scope depend
     * on - but needs no speed percentile, so only the scope's puzzles are asked for one.
     *
     * @phpstan-param list<HistorySolve> $history
     * @param null|array<string, true> $scope solve ids
     * @return array<string, list<XpEntryDraft>> solve id => its drafts, for every solve in the scope ([] = earns nothing)
     */
    public function replay(string $playerId, array $history, null|array $scope = null): array
    {
        $percentilesByPuzzle = $this->getPuzzleSpeedPercentiles->forPuzzles($this->puzzlesNeedingPercentiles($history, $scope));

        $drafts = [];

        /** @var array<string, int> $occurrenceByPuzzle */
        $occurrenceByPuzzle = [];
        /** @var array<string, int> $weeklyCounts */
        $weeklyCounts = [];

        foreach ($history as $solve) {
            $occurrence = ($occurrenceByPuzzle[$solve['puzzle_id']] ?? 0) + 1;
            $occurrenceByPuzzle[$solve['puzzle_id']] = $occurrence;

            $earnedAt = new DateTimeImmutable($solve['earned_at']);
            $weekKey = self::weekKey($earnedAt);
            $inScope = $scope === null || isset($scope[$solve['id']]);

            $awards = $this->xpCalculator->calculate($this->context(
                solve: $solve,
                playerId: $playerId,
                occurrence: $occurrence,
                percentiles: $inScope ? ($percentilesByPuzzle[$solve['puzzle_id']] ?? PuzzleSpeedPercentiles::empty()) : null,
                xpEarningSolvesThisWeek: $weeklyCounts[$weekKey] ?? 0,
            ));

            if ($awards !== []) {
                $weeklyCounts[$weekKey] = ($weeklyCounts[$weekKey] ?? 0) + 1;
            }

            if ($inScope === false) {
                continue;
            }

            $drafts[$solve['id']] = [];

            foreach ($awards as $award) {
                $drafts[$solve['id']][] = new XpEntryDraft(
                    reason: $award->reason,
                    amount: $award->amount,
                    earnedAt: $earnedAt,
                    inWeeklyDelta: true,
                    solvingTimeId: Uuid::fromString($solve['id']),
                );
            }
        }

        return $drafts;
    }

    /**
     * The tiers the solves without a frozen one earned their XP with, read back from the ledger: the tier whose
     * difficulty line the formula gives at the solve's position equals the line the solve holds - today's tier when it
     * does, else the one closest to it. Solves whose base line differs from the formula's (the ledger is behind) are
     * left out, and so are unrated puzzles without a line (their settlement freezes them).
     *
     * @phpstan-param list<HistorySolve> $history
     * @param array<string, array{base: int, difficulty: int}> $earnedBySolve solve id => its base and difficulty lines
     * @return list<array{solvingTimeId: string, puzzleId: string, difficultyTier: null|int}>
     */
    public function inferAwardedTiers(string $playerId, array $history, array $earnedBySolve): array
    {
        $tiers = [];
        /** @var array<string, int> $occurrenceByPuzzle */
        $occurrenceByPuzzle = [];

        foreach ($history as $solve) {
            $occurrence = ($occurrenceByPuzzle[$solve['puzzle_id']] ?? 0) + 1;
            $occurrenceByPuzzle[$solve['puzzle_id']] = $occurrence;

            $earned = $earnedBySolve[$solve['id']] ?? null;

            if ($solve['tier_frozen'] || $earned === null) {
                continue;
            }

            $today = $solve['difficulty_tier'];
            $matching = [];

            foreach ([2, 3, 4, 5, 6] as $tier) {
                $lines = self::linesOf($this->xpCalculator->calculate($this->context(
                    solve: ['difficulty_tier' => $tier] + $solve,
                    playerId: $playerId,
                    occurrence: $occurrence,
                    percentiles: null,
                    xpEarningSolvesThisWeek: XpCalculator::WEEKLY_BOOST_SOLVE_LIMIT,
                )));

                if ($lines['base'] === $earned['base'] && $lines['difficulty'] === $earned['difficulty']) {
                    $matching[] = $tier;
                }
            }

            if ($matching === [] || ($today === null && $earned['difficulty'] === 0)) {
                continue;
            }

            $wanted = max($today ?? 2, 2);
            usort($matching, static fn (int $a, int $b): int => abs($a - $wanted) <=> abs($b - $wanted) ?: $a <=> $b);

            // Tiers 1 and 2 earn alike - keep today's when it is one of them
            $tier = $matching[0] === 2 && $today !== null && $today <= 2 ? $today : $matching[0];

            $tiers[] = ['solvingTimeId' => $solve['id'], 'puzzleId' => $solve['puzzle_id'], 'difficultyTier' => $tier];
        }

        return $tiers;
    }

    /**
     * @param list<XpAward> $awards
     * @return array{base: int, difficulty: int}
     */
    private static function linesOf(array $awards): array
    {
        $lines = ['base' => 0, 'difficulty' => 0];

        foreach ($awards as $award) {
            if ($award->reason === XpReason::SolveBase) {
                $lines['base'] += $award->amount;
            } elseif ($award->reason === XpReason::SolveDifficultyBonus) {
                $lines['difficulty'] += $award->amount;
            }
        }

        return $lines;
    }

    /**
     * The ISO week (UTC) the weekly boost counts in - the same key everywhere the slots are assigned.
     */
    public static function weekKey(DateTimeImmutable $earnedAt): string
    {
        return $earnedAt->format('o-\WW');
    }

    /**
     * @phpstan-param HistorySolve $solve
     */
    private function context(
        array $solve,
        string $playerId,
        int $occurrence,
        null|PuzzleSpeedPercentiles $percentiles,
        int $xpEarningSolvesThisWeek,
    ): SolveXpContext {
        $isBackfill = $this->xpFormulaCutoff->isHistory(new DateTimeImmutable($solve['tracked_at']));
        $isTeamOrDuo = $solve['puzzling_type'] !== 'solo';
        $speedPercentile = SpeedPercentile::None;

        // Out of the scope ($percentiles null) the bonus is not needed: whether a solve earns at all never depends on it
        if ($percentiles !== null && $isBackfill === false && $isTeamOrDuo === false && $solve['seconds_to_solve'] !== null) {
            $speedPercentile = $this->speedPercentile($percentiles, $solve, $playerId);
        }

        return new SolveXpContext(
            piecesCount: $solve['pieces'],
            difficultyTier: $solve['difficulty_tier'],
            isTimed: $solve['seconds_to_solve'] !== null,
            isTeamOrDuo: $isTeamOrDuo,
            unboxed: $solve['unboxed'],
            occurrenceIndex: $occurrence,
            isBackfill: $isBackfill,
            speedPercentile: $speedPercentile,
            xpEarningSolvesThisWeek: $xpEarningSolvesThisWeek,
        );
    }

    /**
     * @phpstan-param HistorySolve $solve
     */
    private function speedPercentile(PuzzleSpeedPercentiles $percentiles, array $solve, string $playerId): SpeedPercentile
    {
        $seconds = $solve['seconds_to_solve'];

        if ($seconds === null) {
            return SpeedPercentile::None;
        }

        if (XpCalculator::isImplausiblyFast($solve['pieces'], $seconds)) {
            // Silent guard: no speed bonus, no user-facing accusation - ops visibility only. Info, not warning: every
            // replay of an implausible solve (rebuild, backfill) must not become a Sentry issue
            $this->logger->info('XP speed bonus denied by plausibility guard', [
                'solvingTimeId' => $solve['id'],
                'playerId' => $playerId,
                'piecesCount' => $solve['pieces'],
                'secondsToSolve' => $seconds,
            ]);

            return SpeedPercentile::None;
        }

        return $percentiles->percentileFor($seconds);
    }

    /**
     * @phpstan-param list<HistorySolve> $history
     * @param null|array<string, true> $scope
     * @return list<string>
     */
    private function puzzlesNeedingPercentiles(array $history, null|array $scope): array
    {
        $puzzleIds = [];

        foreach ($history as $solve) {
            if ($scope !== null && isset($scope[$solve['id']]) === false) {
                continue;
            }

            if ($solve['puzzling_type'] !== 'solo' || $solve['seconds_to_solve'] === null) {
                continue;
            }

            if ($this->xpFormulaCutoff->isHistory(new DateTimeImmutable($solve['tracked_at']))) {
                continue;
            }

            $puzzleIds[$solve['puzzle_id']] = true;
        }

        return array_keys($puzzleIds);
    }
}
