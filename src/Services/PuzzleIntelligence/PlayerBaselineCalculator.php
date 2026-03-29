<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

readonly final class PlayerBaselineCalculator
{
    private const int MINIMUM_SOLVE_COUNT = 5;
    private const float DECAY_HALF_LIFE_MONTHS = 18.0;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{baseline_seconds: int, qualifying_count: int}|null
     */
    public function calculateForPlayer(string $playerId, int $piecesCount): null|array
    {
        $solves = $this->fetchFirstAttemptSolves($playerId, $piecesCount);

        if (count($solves) < self::MINIMUM_SOLVE_COUNT) {
            return null;
        }

        $now = $this->clock->now();
        $weightedSolves = [];

        foreach ($solves as $solve) {
            $finishedAt = new \DateTimeImmutable($solve['solve_date']);
            $ageInMonths = $this->calculateAgeInMonths($finishedAt, $now);
            $weight = exp(-$ageInMonths / self::DECAY_HALF_LIFE_MONTHS);

            $weightedSolves[] = [
                'seconds' => (int) $solve['seconds_to_solve'],
                'weight' => $weight,
            ];
        }

        $baselineSeconds = $this->computeWeightedMedian($weightedSolves);

        return [
            'baseline_seconds' => $baselineSeconds,
            'qualifying_count' => count($weightedSolves),
        ];
    }

    /**
     * Fetch first-attempt solo solves for a player and piece count.
     * Uses the "eldest as proxy" rule: if no solve is marked first_attempt=true,
     * the oldest solve per puzzle is used.
     *
     * @return list<array{seconds_to_solve: int|string, solve_date: string}>
     */
    private function fetchFirstAttemptSolves(string $playerId, int $piecesCount): array
    {
        // CTE: for each puzzle, determine the first attempt
        // Priority: earliest solve with first_attempt=true, else oldest solve
        $sql = "
            WITH first_attempts AS (
                SELECT DISTINCT ON (pst.puzzle_id)
                    pst.seconds_to_solve,
                    COALESCE(pst.finished_at, pst.tracked_at) AS solve_date
                FROM puzzle_solving_time pst
                JOIN puzzle p ON p.id = pst.puzzle_id
                WHERE pst.player_id = :playerId
                    AND p.pieces_count = :piecesCount
                    AND pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve IS NOT NULL
                ORDER BY pst.puzzle_id,
                    pst.first_attempt DESC,
                    COALESCE(pst.finished_at, pst.tracked_at) ASC
            )
            SELECT seconds_to_solve, solve_date
            FROM first_attempts
        ";

        /** @var list<array{seconds_to_solve: int|string, solve_date: string}> */
        return $this->connection->fetchAllAssociative($sql, [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
        ]);
    }

    /**
     * @param list<array{seconds: int, weight: float}> $weightedSolves
     */
    private function computeWeightedMedian(array $weightedSolves): int
    {
        // Sort by seconds ascending
        usort($weightedSolves, static fn (array $a, array $b): int => $a['seconds'] <=> $b['seconds']);

        $totalWeight = array_sum(array_column($weightedSolves, 'weight'));
        $halfWeight = $totalWeight / 2.0;

        $cumulativeWeight = 0.0;

        foreach ($weightedSolves as $solve) {
            $cumulativeWeight += $solve['weight'];

            if ($cumulativeWeight >= $halfWeight) {
                return $solve['seconds'];
            }
        }

        // Fallback — should not happen if data is valid
        $lastKey = array_key_last($weightedSolves);
        assert($lastKey !== null);

        return $weightedSolves[$lastKey]['seconds'];
    }

    private function calculateAgeInMonths(\DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $diff = $from->diff($to);

        return ($diff->y * 12) + $diff->m + ($diff->d / 30.0);
    }
}
