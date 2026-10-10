<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ProgressionDrift;
use SpeedPuzzling\Web\Services\Xp\XpCalculator;
use SpeedPuzzling\Web\Services\Xp\XpFormulaCutoff;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\LevelTable;
use SpeedPuzzling\Web\Value\XpReason;

/**
 * Finds players whose stored progression no longer says what their current records say (the safety net of
 * docs/features/xp-levels/README.md §Anti-farming) - set-based, one statement per check, nothing recomputed per player.
 * Every check compares something that does not depend on puzzle data that moves (difficulty tiers, speed percentiles
 * are read as of the award and may legitimately differ from today's):
 *
 *  - totals: player.xp_total = SUM(ledger), player.level = LevelTable, player.achievement_points = SUM(tiers held)
 *  - deleted solves: their entries net to zero (the compensation is there)
 *  - stale compensation: no solve that exists carries a compensation line (an id that came back after deletion)
 *  - not earning: nobody holds XP for a solve they are not a registered participant of, or that is marked
 *  - base line: every participation's solve_base equals the formula's base part from the current records
 *    (occurrence in the player's chain, relax / timed, pair or team, pieces) - missing awards included
 *  - slots: the weekly boost belongs to the first 5 XP-earning solves of the ISO week only (and to each of them whose
 *    base part reaches 1 XP - below that the moving difficulty decides), the warm-up to exactly the first of the day
 *    (solves logged from the formula cutoff on; history earns neither)
 *
 * Whether the achievements held are still the ones the records qualify for is not set-based (one snapshot per player);
 * the daily myspeedpuzzling:recalculate-badges run re-evaluates everybody and removes what no longer qualifies.
 */
readonly final class GetProgressionDrift
{
    private const string PARTICIPATIONS = <<<SQL
participation AS (
    SELECT pst.id AS solve_id, pst.player_id
    FROM puzzle_solving_time pst
    WHERE pst.suspicious = false
    UNION
    SELECT pst.id, CAST(puzzler ->> 'player_id' AS UUID)
    FROM puzzle_solving_time pst
    CROSS JOIN LATERAL jsonb_array_elements(
        CASE WHEN jsonb_typeof(pst.team::jsonb -> 'puzzlers') = 'array' THEN pst.team::jsonb -> 'puzzlers' ELSE '[]'::jsonb END
    ) AS puzzler
    WHERE pst.suspicious = false
      AND pst.team IS NOT NULL
      AND puzzler ->> 'player_id' ~ '^[0-9a-fA-F-]{36}$'
),
solves AS (
    SELECT
        participation.player_id,
        pst.id,
        pst.tracked_at,
        COALESCE(pst.finished_at, pst.tracked_at) AS earned_at,
        pst.seconds_to_solve IS NOT NULL AS timed,
        pst.puzzling_type <> 'solo' AS group_result,
        COALESCE(pst.pieces_count_snapshot, puzzle.pieces_count) AS pieces,
        ROW_NUMBER() OVER (
            PARTITION BY participation.player_id, pst.puzzle_id
            ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.id
        ) AS occurrence
    FROM participation
    JOIN player ON player.id = participation.player_id
    JOIN puzzle_solving_time pst ON pst.id = participation.solve_id
    JOIN puzzle ON puzzle.id = pst.puzzle_id
),
expected AS (
    SELECT
        player_id,
        id,
        tracked_at,
        earned_at,
        CASE
            WHEN timed AND occurrence = 1 THEN 1.0
            WHEN timed AND occurrence = 2 THEN 0.5
            WHEN timed THEN 0.25
            WHEN occurrence = 1 THEN 0.5
            ELSE 0
        END AS occurrence_multiplier,
        -- XpCalculator: base = pieces / 100 within 1..60, x 0.75 for a pair or team
        LEAST(GREATEST(CAST(pieces AS NUMERIC) / 100, 1.0), 60.0)
            * CASE WHEN group_result THEN 0.75 ELSE 1.0 END AS base_before_occurrence
    FROM solves
),
expected_base AS (
    SELECT
        player_id,
        id,
        tracked_at,
        earned_at,
        occurrence_multiplier > 0 AS earns,
        base_before_occurrence * occurrence_multiplier AS base_part,
        CASE
            WHEN occurrence_multiplier > 0 THEN GREATEST(ROUND(base_before_occurrence * occurrence_multiplier), 1)
            ELSE 0
        END AS base
    FROM expected
),
ledger AS (
    SELECT
        player_id,
        solving_time_id,
        COALESCE(SUM(amount) FILTER (WHERE reason = :solveBase), 0) AS base,
        COUNT(*) FILTER (WHERE reason = :weeklyBoost) AS boosts,
        COUNT(*) FILTER (WHERE reason = :dailyWarmup) AS warmups,
        SUM(amount) AS net
    FROM xp_entry
    WHERE solving_time_id IS NOT NULL
    GROUP BY player_id, solving_time_id
)
SQL;

    public function __construct(
        private Connection $database,
        private XpFormulaCutoff $xpFormulaCutoff,
    ) {
    }

    public function find(): ProgressionDrift
    {
        return new ProgressionDrift(
            xpTotalNotLedger: $this->playerIds(<<<SQL
SELECT p.id
FROM player p
LEFT JOIN (SELECT player_id, SUM(amount) AS total FROM xp_entry GROUP BY player_id) ledger ON ledger.player_id = p.id
WHERE p.xp_total <> COALESCE(ledger.total, 0)
SQL),
            levelNotXp: $this->levelMismatches(),
            achievementPointsNotTiers: $this->playerIds(sprintf(<<<SQL
SELECT p.id
FROM player p
LEFT JOIN (SELECT b.player_id, SUM(%s) AS points FROM badge b GROUP BY b.player_id) earned ON earned.player_id = p.id
WHERE p.achievement_points <> COALESCE(earned.points, 0)
SQL, $this->pointsCase())),
            deletedSolveNotCompensated: $this->playerIds(<<<SQL
SELECT DISTINCT e.player_id
FROM xp_entry e
WHERE e.solving_time_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM puzzle_solving_time pst WHERE pst.id = e.solving_time_id)
GROUP BY e.player_id, e.solving_time_id
HAVING SUM(e.amount) <> 0
SQL),
            staleCompensation: $this->playerIds(<<<SQL
SELECT DISTINCT e.player_id
FROM xp_entry e
JOIN puzzle_solving_time pst ON pst.id = e.solving_time_id
WHERE e.reason = :compensation
SQL),
            xpWithoutParticipation: $this->playerIds(
                'WITH ' . self::PARTICIPATIONS . <<<SQL

SELECT DISTINCT ledger.player_id
FROM ledger
JOIN puzzle_solving_time pst ON pst.id = ledger.solving_time_id
WHERE ledger.net <> 0
  AND NOT EXISTS (
    SELECT 1 FROM participation WHERE participation.player_id = ledger.player_id AND participation.solve_id = ledger.solving_time_id
  )
SQL,
            ),
            baseNotFormula: $this->playerIds(
                'WITH ' . self::PARTICIPATIONS . <<<SQL

SELECT DISTINCT expected_base.player_id
FROM expected_base
LEFT JOIN ledger ON ledger.player_id = expected_base.player_id AND ledger.solving_time_id = expected_base.id
WHERE COALESCE(ledger.base, 0) <> expected_base.base
SQL,
            ),
            slotsNotFirstSolves: $this->playerIds(
                'WITH ' . self::PARTICIPATIONS . <<<SQL
,
ranked AS (
    SELECT
        player_id,
        id,
        tracked_at,
        base_part >= 1 AS certainly_boosted,
        ROW_NUMBER() OVER (PARTITION BY player_id, DATE_TRUNC('week', earned_at) ORDER BY earned_at, id) AS week_rank,
        ROW_NUMBER() OVER (PARTITION BY player_id, DATE_TRUNC('day', earned_at) ORDER BY earned_at, id) AS day_rank
    FROM expected_base
    WHERE earns
)
SELECT DISTINCT ranked.player_id
FROM ranked
LEFT JOIN ledger ON ledger.player_id = ranked.player_id AND ledger.solving_time_id = ranked.id
WHERE CASE
    WHEN ranked.tracked_at >= CAST(:cutoff AS TIMESTAMP) THEN
        (ranked.week_rank > :weeklyLimit AND COALESCE(ledger.boosts, 0) > 0)
        -- the boost is half the core rounded: certainly there from a base part of 1, below it the difficulty and
        -- unboxed parts decide
        OR (ranked.week_rank <= :weeklyLimit AND ranked.certainly_boosted AND COALESCE(ledger.boosts, 0) = 0)
        OR (ranked.day_rank = 1) <> (COALESCE(ledger.warmups, 0) = 1)
        OR COALESCE(ledger.boosts, 0) > 1
        OR COALESCE(ledger.warmups, 0) > 1
    ELSE COALESCE(ledger.boosts, 0) > 0 OR COALESCE(ledger.warmups, 0) > 0
END
SQL,
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function playerIds(string $sql): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->executeQuery($sql, $this->parametersOf($sql))->fetchFirstColumn();

        return $ids;
    }

    /**
     * @return array<string, int|float|string>
     */
    private function parametersOf(string $sql): array
    {
        $all = [
            'compensation' => XpReason::SolveCompensation->value,
            'solveBase' => XpReason::SolveBase->value,
            'weeklyBoost' => XpReason::SolveWeeklyBoost->value,
            'dailyWarmup' => XpReason::SolveDailyWarmup->value,
            'cutoff' => $this->xpFormulaCutoff->fullFormulaFrom()->format('Y-m-d H:i:s'),
            'weeklyLimit' => XpCalculator::WEEKLY_BOOST_SOLVE_LIMIT,
        ];

        return array_filter(
            $all,
            static fn (string $name): bool => str_contains($sql, ':' . $name),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return list<string>
     */
    private function levelMismatches(): array
    {
        /** @var list<array{xp_total: int|string, level: int|string}> $pairs */
        $pairs = $this->database
            ->executeQuery('SELECT DISTINCT xp_total, level FROM player')
            ->fetchAllAssociative();

        $ids = [];

        foreach ($pairs as $pair) {
            if (LevelTable::levelForXp((int) $pair['xp_total']) === (int) $pair['level']) {
                continue;
            }

            /** @var list<string> $playerIds */
            $playerIds = $this->database->fetchFirstColumn(
                'SELECT id FROM player WHERE xp_total = :xpTotal AND level = :level',
                ['xpTotal' => (int) $pair['xp_total'], 'level' => (int) $pair['level']],
            );

            $ids = [...$ids, ...$playerIds];
        }

        return $ids;
    }

    private function pointsCase(): string
    {
        $case = 'CASE b.tier';

        foreach (BadgeTier::cases() as $tier) {
            $case .= sprintf(' WHEN %d THEN %d', $tier->value, $tier->points());
        }

        return $case . sprintf(' ELSE %d END', BadgeTier::SINGLE_TIER_POINTS);
    }
}
