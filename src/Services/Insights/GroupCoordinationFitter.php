<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\ChangedRowsSync;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceHistoryReadiness;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimelineLoader;
use SpeedPuzzling\Web\Value\GroupCoordination;

/**
 * Refits the pair/team coordination constants (group_coordination_constant). For every pair/team result that can be
 * compared with solo paces - every member a registered player with a pace at the solve, a puzzle with a difficulty
 * score of more than insufficient confidence, boxed, not suspicious, not a secret puzzle - the ratio
 * time × Σ 1/paceᵢ ÷ difficulty, every pace as of the solve from the pace history (GroupExpectation - the rule the
 * synergy compares with, and the one the as-of difficulty is built on); the constant is the median ratio per group
 * size (2, 3, 4+) and first try / repeat (the result's first_attempt flag, which is set only when it is everybody's
 * first try). Buckets with fewer than GroupCoordination::MINIMUM_SAMPLES results keep the seed, and so does every
 * bucket before the pace history is built. Two statements and the whole pace history in memory (~10 MB) - daily.
 */
readonly final class GroupCoordinationFitter
{
    public function __construct(
        private Connection $connection,
        private ChangedRowsSync $sync,
        private PaceHistoryReadiness $paceHistoryReadiness,
        private PaceTimelineLoader $paceTimelineLoader,
    ) {
    }

    /**
     * @return array<int, array<string, array{coordination: float, sample_size: int, fitted: bool}>> size => first|repeat => constant
     */
    public function fit(\DateTimeImmutable $now): array
    {
        /** @var array<int, array<string, list<float>>> $ratios size group => first|repeat => ratios */
        $ratios = [];
        $scalingExponent = $this->paceHistoryReadiness->scalingExponent();

        if ($scalingExponent !== null) {
            $timeline = $this->paceTimelineLoader->all($scalingExponent);

            foreach ($this->comparableResults($now) as $result) {
                /** @var list<string> $members */
                $members = json_decode($result['members'], true, flags: JSON_THROW_ON_ERROR);
                $rate = GroupExpectation::combinedRate($timeline, $members, (int) $result['pieces_count'], (float) $result['solved_ts'], (float) $result['tracked_ts']);

                if ($rate === null) {
                    continue;
                }

                $first = $result['first_attempt'] === true || $result['first_attempt'] === 1 || $result['first_attempt'] === '1' || $result['first_attempt'] === 't';
                $ratios[GroupCoordination::sizeGroup(count($members))][$first ? 'first' : 'repeat'][] = (int) $result['seconds_to_solve'] * $rate / (float) $result['difficulty_score'];
            }
        }

        $constants = [];

        foreach (GroupCoordination::SIZES as $size) {
            foreach (['first', 'repeat'] as $kind) {
                $bucket = $ratios[$size][$kind] ?? [];
                $fitted = count($bucket) >= GroupCoordination::MINIMUM_SAMPLES;

                $constants[$size][$kind] = [
                    'coordination' => $fitted ? round(self::median($bucket), 4) : GroupCoordination::SEEDS[$size][$kind],
                    'sample_size' => count($bucket),
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

    /**
     * Pair/team results whose every member is a registered player, on a rated, boxed, not secret puzzle.
     *
     * @return iterable<array{seconds_to_solve: int|string, first_attempt: bool|int|string, difficulty_score: float|string, pieces_count: int|string, members: string, solved_ts: float|int|string, tracked_ts: float|int|string}>
     */
    private function comparableResults(\DateTimeImmutable $now): iterable
    {
        /** @var iterable<array{seconds_to_solve: int|string, first_attempt: bool|int|string, difficulty_score: float|string, pieces_count: int|string, members: string, solved_ts: float|int|string, tracked_ts: float|int|string}> $rows */
        $rows = $this->connection->executeQuery("
            WITH team_members AS MATERIALIZED (
                SELECT ptm.team_id, json_agg(ptm.player_id ORDER BY ptm.player_id) AS members
                FROM puzzling_team_member ptm
                GROUP BY ptm.team_id
                HAVING COUNT(*) >= 2 AND COUNT(ptm.player_id) = COUNT(*)
            )
            SELECT
                pst.seconds_to_solve,
                pst.first_attempt,
                pd.difficulty_score,
                p.pieces_count,
                tm.members,
                EXTRACT(EPOCH FROM COALESCE(pst.finished_at, pst.tracked_at)) AS solved_ts,
                EXTRACT(EPOCH FROM pst.tracked_at) AS tracked_ts
            FROM puzzle_solving_time pst
            JOIN team_members tm ON tm.team_id = pst.puzzling_team_id
            JOIN puzzle p ON p.id = pst.puzzle_id
            JOIN puzzle_difficulty pd ON pd.puzzle_id = pst.puzzle_id
            WHERE pst.puzzling_type IN ('duo', 'team')
                AND pst.suspicious = false
                AND pst.seconds_to_solve > 0
                AND pst.unboxed = false
                AND pd.difficulty_score > 0
                AND pd.confidence <> 'insufficient'
                AND (p.hide_until IS NULL OR p.hide_until <= :now)
                AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
        ", ['now' => $now->format('Y-m-d H:i:s')])->iterateAssociative();

        return $rows;
    }

    /**
     * percentile_cont(0.5): the middle value, or the mean of the two middle ones
     *
     * @param non-empty-list<float> $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2.0;
    }
}
