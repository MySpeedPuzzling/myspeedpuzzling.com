<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use DateTimeImmutable;

/**
 * The rows of player_pace_history for one player and piece count, from the pair's qualifying first
 * attempts in the insights order. Row k (k ≥ 5) = the direct baseline right after the k-th solve:
 * PlayerBaselineCalculator::weightedBaselineSeconds() over solves 1..k, ages measured to the k-th
 * solve. No database, no state.
 */
final readonly class PlayerPaceHistoryCalculator
{
    /**
     * Part of every checksum: bump it whenever what a row holds changes for the same inputs (the
     * baseline rule, the step, the drift) - every pair is then rewritten by the next batch run.
     */
    public const int VERSION = 1;

    public function __construct(
        private PlayerBaselineCalculator $baselineCalculator,
    ) {
    }

    /**
     * Checksum of the pair's inputs after each solve (index 0 = after the 1st): the running sum of the
     * solves' hashes. The solves come sorted, so equal checksums up to a position mean the same solves
     * up to it - the first position whose checksum differs from the stored one is the first changed
     * solve, and the last one is what SQL sums over the whole pair (sqlSolveHash()) to spot a change.
     *
     * @param list<array{id: string, seconds: int, solve_date: string, tracked_at: string}> $solves
     * @return list<int>
     */
    public static function checksums(array $solves): array
    {
        $checksum = 0;
        $checksums = [];

        foreach ($solves as $solve) {
            $checksum += self::solveHash($solve);
            $checksums[] = $checksum;
        }

        return $checksums;
    }

    /**
     * The hash of one qualifying solve (48 bits of an md5: the sum over a pair of thousands of solves
     * stays far inside a bigint).
     *
     * @param array{id: string, seconds: int, solve_date: string, tracked_at: string} $solve
     */
    public static function solveHash(array $solve): int
    {
        $text = 'pace-v' . self::VERSION . '|' . $solve['id'] . '|' . $solve['seconds'] . '|' . $solve['solve_date'] . '|' . $solve['tracked_at'];

        return (int) hexdec(substr(md5($text), 0, 12));
    }

    /**
     * solveHash() as SQL over a row of PlayerBaselineCalculator::qualifyingFirstAttemptsQuery() -
     * the same number (pinned by PlayerPaceHistoryTest).
     */
    public static function sqlSolveHash(): string
    {
        return "('x' || substr(md5('pace-v" . self::VERSION . "|' || id || '|' || seconds_to_solve || '|' || solve_date || '|' || tracked_at), 1, 12))::bit(48)::bigint";
    }

    /**
     * The rows from position $from (1-based, at least MINIMUM_SOLVE_COUNT) to the last solve.
     *
     * @param list<array{id: string, seconds: int, solve_date: string, tracked_at: string}> $solves in the insights order
     * @param list<int> $checksums self::checksums($solves)
     * @return list<array{qualifying_count: int, time_id: string, valid_from: string, valid_from_tracked_at: string, baseline_seconds: int, step: null|float, inputs_checksum: int}>
     */
    public function rows(array $solves, array $checksums, int $from): array
    {
        $from = max($from, PlayerBaselineCalculator::MINIMUM_SOLVE_COUNT);
        $count = count($solves);

        if ($count < $from) {
            return [];
        }

        /** @var list<array{seconds: int, solved_at: DateTimeImmutable}> $inputs */
        $inputs = array_map(static fn (array $solve): array => [
            'seconds' => $solve['seconds'],
            'solved_at' => new DateTimeImmutable($solve['solve_date']),
        ], $solves);

        // The step of the first written row needs the baseline before it
        $previous = $from > PlayerBaselineCalculator::MINIMUM_SOLVE_COUNT
            ? $this->baselineAfter($inputs, $from - 1)
            : null;

        $rows = [];

        for ($position = $from; $position <= $count; $position++) {
            $baseline = $this->baselineAfter($inputs, $position);
            $solve = $solves[$position - 1];

            $rows[] = [
                'qualifying_count' => $position,
                'time_id' => $solve['id'],
                'valid_from' => $solve['solve_date'],
                'valid_from_tracked_at' => $solve['tracked_at'],
                'baseline_seconds' => $baseline,
                'step' => $previous !== null ? round(log($baseline / $previous), 6) : null,
                'inputs_checksum' => $checksums[$position - 1],
            ];

            $previous = $baseline;
        }

        return $rows;
    }

    /**
     * @param list<array{seconds: int, solved_at: DateTimeImmutable}> $inputs
     */
    private function baselineAfter(array $inputs, int $position): int
    {
        $prefix = array_slice($inputs, 0, $position);
        assert($prefix !== []);

        return $this->baselineCalculator->weightedBaselineSeconds($prefix, $prefix[$position - 1]['solved_at']);
    }
}
