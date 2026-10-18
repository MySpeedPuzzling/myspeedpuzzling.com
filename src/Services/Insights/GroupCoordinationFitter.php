<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\ChangedRowsSync;
use SpeedPuzzling\Web\Value\GroupCoordination;

/**
 * Refits the pair/team coordination constants (group_coordination_constant). For every pair/team result that can be
 * compared with solo paces - every member a registered player with a baseline at the puzzle's piece count, a puzzle
 * with a difficulty score of more than insufficient confidence, boxed, not suspicious, not a secret puzzle - the ratio
 * time ÷ (difficulty × 1 / Σ 1/baselineᵢ); the constant is the median ratio per group size (2, 3, 4+) and first try /
 * repeat (the result's first_attempt flag, which is set only when it is everybody's first try). Buckets with fewer than
 * GroupCoordination::MINIMUM_SAMPLES results keep the seed. One statement, ~0.6 s on the production copy - daily.
 */
readonly final class GroupCoordinationFitter
{
    public function __construct(
        private Connection $connection,
        private ChangedRowsSync $sync,
    ) {
    }

    /**
     * @return array<int, array<string, array{coordination: float, sample_size: int, fitted: bool}>> size => first|repeat => constant
     */
    public function fit(\DateTimeImmutable $now): array
    {
        /** @var list<array{size_group: int|string, first_attempt: bool|int|string, sample_size: int|string, coordination: float|string}> $rows */
        $rows = $this->connection->fetchAllAssociative("
            WITH team_size AS MATERIALIZED (
                SELECT ptm.team_id, COUNT(*) AS members
                FROM puzzling_team_member ptm
                GROUP BY ptm.team_id
                HAVING COUNT(*) >= 2 AND COUNT(ptm.player_id) = COUNT(*)
            ),
            group_result AS (
                -- One row per comparable result: the members' combined solo rate, and how many members have a baseline
                SELECT
                    pst.id,
                    pst.seconds_to_solve,
                    pst.first_attempt,
                    pd.difficulty_score,
                    ts.members,
                    SUM(1.0 / pb.baseline_seconds) AS rate,
                    COUNT(pb.player_id) AS with_baseline
                FROM puzzle_solving_time pst
                JOIN team_size ts ON ts.team_id = pst.puzzling_team_id
                JOIN puzzle p ON p.id = pst.puzzle_id
                JOIN puzzle_difficulty pd ON pd.puzzle_id = pst.puzzle_id
                JOIN puzzling_team_member ptm ON ptm.team_id = pst.puzzling_team_id
                LEFT JOIN player_baseline pb ON pb.player_id = ptm.player_id AND pb.pieces_count = p.pieces_count AND pb.baseline_seconds > 0
                WHERE pst.puzzling_type IN ('duo', 'team')
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve > 0
                    AND pst.unboxed = false
                    AND pd.difficulty_score IS NOT NULL
                    AND pd.confidence <> 'insufficient'
                    AND (p.hide_until IS NULL OR p.hide_until <= :now)
                    AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
                GROUP BY pst.id, pst.seconds_to_solve, pst.first_attempt, pd.difficulty_score, ts.members
            )
            SELECT
                LEAST(members, 4) AS size_group,
                first_attempt,
                COUNT(*) AS sample_size,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY seconds_to_solve * rate / difficulty_score) AS coordination
            FROM group_result
            WHERE with_baseline = members
            GROUP BY 1, 2
        ", ['now' => $now->format('Y-m-d H:i:s')]);

        $measured = [];

        foreach ($rows as $row) {
            $first = $row['first_attempt'] === true || $row['first_attempt'] === 1 || $row['first_attempt'] === '1' || $row['first_attempt'] === 't';
            $measured[(int) $row['size_group']][$first ? 'first' : 'repeat'] = [
                'coordination' => (float) $row['coordination'],
                'sample_size' => (int) $row['sample_size'],
            ];
        }

        $constants = [];

        foreach (GroupCoordination::SIZES as $size) {
            foreach (['first', 'repeat'] as $kind) {
                $bucket = $measured[$size][$kind] ?? ['coordination' => 0.0, 'sample_size' => 0];
                $fitted = $bucket['sample_size'] >= GroupCoordination::MINIMUM_SAMPLES;

                $constants[$size][$kind] = [
                    'coordination' => $fitted ? round($bucket['coordination'], 4) : GroupCoordination::SEEDS[$size][$kind],
                    'sample_size' => $bucket['sample_size'],
                    'fitted' => $fitted,
                ];
            }
        }

        return $constants;
    }

    /**
     * @return array{written: int, deleted: int}
     */
    public function refit(\DateTimeImmutable $now): array
    {
        $run = $this->sync->open(
            'group_coordination_constant',
            ['group_size' => 'smallint', 'first_attempt' => 'boolean'],
            ['coordination' => 'double precision', 'sample_size' => 'integer', 'fitted' => 'boolean'],
        );

        foreach ($this->fit($now) as $size => $kinds) {
            foreach ($kinds as $kind => $constant) {
                $run->add([
                    'group_size' => $size,
                    'first_attempt' => $kind === 'first',
                    'coordination' => $constant['coordination'],
                    'sample_size' => $constant['sample_size'],
                    'fitted' => $constant['fitted'],
                ]);
            }
        }

        return $run->finish($now);
    }
}
