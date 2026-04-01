<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Value\MetricConfidence;
use SpeedPuzzling\Web\Value\SkillTier;
use Symfony\Contracts\Service\ResetInterface;

final class PlayerSkillCalculator implements ResetInterface
{
    public const int MINIMUM_QUALIFYING_PUZZLES = 10;
    public const int MIN_SOLVERS_PER_PUZZLE = 20;
    private const float DIFFICULTY_BLEND = 0.5;
    private const int DIFF_CONFIDENCE_THRESHOLD = 50;
    private const float DECAY_PLATEAU_MONTHS = 6.0;
    private const float DECAY_RATE_MONTHS = 24.0;

    /**
     * Pre-computed puzzle data: sorted first-attempt times + difficulty info.
     *
     * @var array<string, array{sorted_times: list<int>, difficulty_score: float, sample_size: int}>|null
     */
    private null|array $puzzleCache = null;

    /**
     * Per-player first-attempt times per puzzle with solve dates.
     * Uses "eldest as proxy" rule: prefers first_attempt=true, falls back to earliest solve.
     *
     * @var array<string, array<string, array{time: int, solve_date: string, is_first_attempt: bool}>>|null player_id => puzzle_id => data
     */
    private null|array $playerPuzzleTimesCache = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Bulk-load all puzzle ranking data and player times for a piece count.
     * Call once before the per-player loop.
     */
    public function preloadPuzzleData(int $piecesCount): void
    {
        // Load puzzle difficulty data
        /** @var list<array{puzzle_id: string, difficulty_score: float|string, sample_size: int|string}> $difficulties */
        $difficulties = $this->connection->fetchAllAssociative("
            SELECT pd.puzzle_id, pd.difficulty_score, pd.sample_size
            FROM puzzle_difficulty pd
            JOIN puzzle p ON p.id = pd.puzzle_id
            WHERE p.pieces_count = :piecesCount
                AND pd.difficulty_score IS NOT NULL
                AND pd.confidence != 'insufficient'
        ", ['piecesCount' => $piecesCount]);

        $difficultyMap = [];

        foreach ($difficulties as $row) {
            $difficultyMap[$row['puzzle_id']] = [
                'difficulty_score' => (float) $row['difficulty_score'],
                'sample_size' => (int) $row['sample_size'],
            ];
        }

        // Load strict first-attempt times per puzzle (for comparison pool / sorted_times)
        /** @var list<array{puzzle_id: string, seconds_to_solve: int|string}> $strictFirstAttempts */
        $strictFirstAttempts = $this->connection->fetchAllAssociative("
            SELECT pst.puzzle_id, pst.seconds_to_solve
            FROM puzzle_solving_time pst
            JOIN puzzle p ON p.id = pst.puzzle_id
            WHERE p.pieces_count = :piecesCount
                AND pst.first_attempt = true
                AND pst.puzzling_type = 'solo'
                AND pst.suspicious = false
                AND pst.seconds_to_solve IS NOT NULL
        ", ['piecesCount' => $piecesCount]);

        // Build sorted times per puzzle (comparison pool)
        $puzzleTimesMap = [];

        foreach ($strictFirstAttempts as $row) {
            $puzzleTimesMap[$row['puzzle_id']][] = (int) $row['seconds_to_solve'];
        }

        // Load player times using "eldest as proxy" rule: prefer first_attempt=true, fall back to earliest solve
        /** @var list<array{puzzle_id: string, player_id: string, seconds_to_solve: int|string, solve_date: string, is_first_attempt: string}> $playerFirstAttempts */
        $playerFirstAttempts = $this->connection->fetchAllAssociative("
            SELECT puzzle_id, player_id, seconds_to_solve, solve_date, is_first_attempt
            FROM (
                SELECT
                    pst.puzzle_id,
                    pst.player_id,
                    pst.seconds_to_solve,
                    COALESCE(pst.finished_at, pst.tracked_at) AS solve_date,
                    pst.first_attempt AS is_first_attempt,
                    ROW_NUMBER() OVER (
                        PARTITION BY pst.player_id, pst.puzzle_id
                        ORDER BY pst.first_attempt DESC, COALESCE(pst.finished_at, pst.tracked_at) ASC
                    ) AS rn
                FROM puzzle_solving_time pst
                JOIN puzzle p ON p.id = pst.puzzle_id
                WHERE p.pieces_count = :piecesCount
                    AND pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve IS NOT NULL
            ) sub
            WHERE rn = 1
        ", ['piecesCount' => $piecesCount]);

        $playerTimes = [];

        foreach ($playerFirstAttempts as $row) {
            $playerTimes[$row['player_id']][$row['puzzle_id']] = [
                'time' => (int) $row['seconds_to_solve'],
                'solve_date' => $row['solve_date'],
                'is_first_attempt' => (bool) $row['is_first_attempt'],
            ];
        }

        // Build final puzzle cache (sorted times + difficulty, filtered by min solvers)
        $puzzleCache = [];

        foreach ($puzzleTimesMap as $puzzleId => $times) {
            if (!isset($difficultyMap[$puzzleId])) {
                continue;
            }

            if (count($times) < self::MIN_SOLVERS_PER_PUZZLE) {
                continue;
            }

            sort($times);
            $puzzleCache[$puzzleId] = [
                'sorted_times' => $times,
                'difficulty_score' => $difficultyMap[$puzzleId]['difficulty_score'],
                'sample_size' => $difficultyMap[$puzzleId]['sample_size'],
            ];
        }

        $this->puzzleCache = $puzzleCache;
        $this->playerPuzzleTimesCache = $playerTimes;
    }

    public function clearPreloadedData(): void
    {
        $this->puzzleCache = null;
        $this->playerPuzzleTimesCache = null;
    }

    public function reset(): void
    {
        $this->puzzleCache = null;
        $this->playerPuzzleTimesCache = null;
    }

    /**
     * @return array{
     *     skill_score: float,
     *     skill_tier: SkillTier,
     *     skill_percentile: float,
     *     confidence: MetricConfidence,
     *     qualifying_puzzles_count: int,
     * }|null
     */
    public function calculateForPlayer(string $playerId, int $piecesCount): null|array
    {
        $score = $this->calculateScoreForPlayer($playerId, $piecesCount);

        if ($score === null) {
            return null;
        }

        $percentile = $this->computePercentile($piecesCount, $score['skill_score']);

        return [
            'skill_score' => $score['skill_score'],
            'skill_tier' => SkillTier::fromPercentile($percentile),
            'skill_percentile' => round($percentile, 2),
            'confidence' => $score['confidence'],
            'qualifying_puzzles_count' => $score['qualifying_puzzles_count'],
        ];
    }

    /**
     * Compute raw skill score without percentile/tier (used by batch orchestrator).
     *
     * @return array{skill_score: float, confidence: MetricConfidence, qualifying_puzzles_count: int}|null
     */
    public function calculateScoreForPlayer(string $playerId, int $piecesCount): null|array
    {
        $entries = $this->computeWeightedPercentileEntries($playerId, $piecesCount);

        if (count($entries) < self::MINIMUM_QUALIFYING_PUZZLES) {
            return null;
        }

        $skillScore = $this->computeWeightedMedian($entries);
        $confidence = MetricConfidence::fromSampleSize(count($entries), self::MINIMUM_QUALIFYING_PUZZLES);

        return [
            'skill_score' => round($skillScore, 6),
            'confidence' => $confidence,
            'qualifying_puzzles_count' => count($entries),
        ];
    }

    /**
     * For each puzzle the player solved (first attempt), compute:
     *   percentile among all first-attempt solvers
     *   difficulty_weight with confidence scaling
     *   weighted_percentile = percentile x difficulty_weight
     *   age_weight = exponential decay based on solve recency
     *
     * @return list<array{value: float, weight: float}>
     */
    private function computeWeightedPercentileEntries(string $playerId, int $piecesCount): array
    {
        if ($this->puzzleCache !== null && $this->playerPuzzleTimesCache !== null) {
            return $this->computeEntriesFromCache($playerId);
        }

        return $this->computeEntriesFromDb($playerId, $piecesCount);
    }

    /**
     * @return list<array{value: float, weight: float}>
     */
    private function computeEntriesFromCache(string $playerId): array
    {
        assert($this->puzzleCache !== null && $this->playerPuzzleTimesCache !== null);

        $playerTimes = $this->playerPuzzleTimesCache[$playerId] ?? [];
        $entries = [];
        $now = $this->clock->now();

        foreach ($playerTimes as $puzzleId => $solveData) {
            if (!isset($this->puzzleCache[$puzzleId])) {
                continue;
            }

            $puzzle = $this->puzzleCache[$puzzleId];
            $sortedTimes = $puzzle['sorted_times'];
            $totalSolvers = count($sortedTimes);
            $playerTime = $solveData['time'];
            $isFirstAttempt = $solveData['is_first_attempt'];

            // Count slower and tied using sorted array
            $slowerCount = 0;
            $tiedCount = 0;

            foreach ($sortedTimes as $time) {
                if ($time > $playerTime) {
                    $slowerCount++;
                } elseif ($time === $playerTime) {
                    $tiedCount++;
                }
            }

            // Exclude self from tied count and denominator only if player is in the comparison pool
            if ($isFirstAttempt) {
                $tiedOthers = max(0, $tiedCount - 1);
                $denominator = $totalSolvers - 1;
            } else {
                $tiedOthers = $tiedCount;
                $denominator = $totalSolvers;
            }

            if ($denominator <= 0) {
                continue;
            }

            $percentile = ($slowerCount + $tiedOthers / 2.0) / $denominator;

            // Confidence-scaled difficulty weight
            $confidence = min(1.0, $puzzle['sample_size'] / self::DIFF_CONFIDENCE_THRESHOLD);
            $blend = self::DIFFICULTY_BLEND * $confidence;
            $difficultyWeight = (1.0 - $blend) + $blend * $puzzle['difficulty_score'];

            // Time decay weight
            $solveDate = new \DateTimeImmutable($solveData['solve_date']);
            $ageWeight = $this->computeDecayWeight($solveDate, $now);

            $entries[] = [
                'value' => $percentile * $difficultyWeight,
                'weight' => $ageWeight,
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{value: float, weight: float}>
     */
    private function computeEntriesFromDb(string $playerId, int $piecesCount): array
    {
        $sql = "
            WITH player_first_attempts AS (
                SELECT DISTINCT ON (pst.puzzle_id)
                    pst.puzzle_id,
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
            ),
            puzzle_rankings AS (
                SELECT
                    pfa.puzzle_id,
                    pfa.seconds_to_solve AS player_time,
                    pfa.solve_date,
                    pd.difficulty_score,
                    pd.sample_size,
                    COUNT(*) FILTER (WHERE all_fa.seconds_to_solve > pfa.seconds_to_solve) AS slower_count,
                    COUNT(*) FILTER (WHERE all_fa.seconds_to_solve = pfa.seconds_to_solve AND all_fa.player_id != :playerId) AS tied_count,
                    COUNT(*) AS total_solvers
                FROM player_first_attempts pfa
                JOIN puzzle_difficulty pd ON pd.puzzle_id = pfa.puzzle_id
                JOIN puzzle_solving_time all_fa ON all_fa.puzzle_id = pfa.puzzle_id
                    AND all_fa.puzzling_type = 'solo'
                    AND all_fa.suspicious = false
                    AND all_fa.seconds_to_solve IS NOT NULL
                    AND all_fa.first_attempt = true
                WHERE pd.difficulty_score IS NOT NULL
                    AND pd.confidence != 'insufficient'
                GROUP BY pfa.puzzle_id, pfa.seconds_to_solve, pfa.solve_date, pd.difficulty_score, pd.sample_size
                HAVING COUNT(*) >= :minSolvers
            )
            SELECT
                puzzle_id,
                player_time,
                solve_date,
                difficulty_score,
                sample_size,
                slower_count,
                tied_count,
                total_solvers
            FROM puzzle_rankings
        ";

        /** @var list<array{puzzle_id: string, player_time: int|string, solve_date: string, difficulty_score: float|string, sample_size: int|string, slower_count: int|string, tied_count: int|string, total_solvers: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
            'minSolvers' => self::MIN_SOLVERS_PER_PUZZLE,
        ]);

        $entries = [];
        $now = $this->clock->now();

        foreach ($rows as $row) {
            $totalSolvers = (int) $row['total_solvers'];
            $slowerCount = (int) $row['slower_count'];
            $tiedCount = (int) $row['tied_count'];
            $difficultyScore = (float) $row['difficulty_score'];
            $sampleSize = (int) $row['sample_size'];

            if ($totalSolvers <= 1) {
                continue;
            }

            // Average rank method for ties
            $percentile = ($slowerCount + $tiedCount / 2.0) / ($totalSolvers - 1);

            // Confidence-scaled difficulty weight
            $confidence = min(1.0, $sampleSize / self::DIFF_CONFIDENCE_THRESHOLD);
            $blend = self::DIFFICULTY_BLEND * $confidence;
            $difficultyWeight = (1.0 - $blend) + $blend * $difficultyScore;

            // Time decay weight
            $solveDate = new \DateTimeImmutable($row['solve_date']);
            $ageWeight = $this->computeDecayWeight($solveDate, $now);

            $entries[] = [
                'value' => $percentile * $difficultyWeight,
                'weight' => $ageWeight,
            ];
        }

        return $entries;
    }

    /**
     * Compute what percentile this player's skill score falls in
     * among all players with valid skill scores for this piece count.
     */
    private function computePercentile(int $piecesCount, float $skillScore): float
    {
        $result = $this->connection->fetchAssociative("
            SELECT
                COUNT(*) FILTER (WHERE skill_score <= :skillScore) AS below_or_equal,
                COUNT(*) AS total
            FROM player_skill
            WHERE pieces_count = :piecesCount
        ", [
            'skillScore' => $skillScore,
            'piecesCount' => $piecesCount,
        ]);

        /** @var array{below_or_equal: int|string, total: int|string}|false $result */
        if ($result === false || (int) $result['total'] === 0) {
            return 50.0;
        }

        $total = (int) $result['total'];
        $belowOrEqual = (int) $result['below_or_equal'];

        return ($belowOrEqual / $total) * 100.0;
    }

    /**
     * Weighted median: sorts entries by value, accumulates weights,
     * returns the value where cumulative weight crosses 50%.
     * Recent solves (higher weight) have more influence on the result.
     *
     * @param list<array{value: float, weight: float}> $entries
     */
    private function computeWeightedMedian(array $entries): float
    {
        usort($entries, static fn (array $a, array $b): int => $a['value'] <=> $b['value']);

        $totalWeight = array_sum(array_column($entries, 'weight'));
        $halfWeight = $totalWeight / 2.0;
        $cumulativeWeight = 0.0;

        foreach ($entries as $entry) {
            $cumulativeWeight += $entry['weight'];

            if ($cumulativeWeight >= $halfWeight) {
                return $entry['value'];
            }
        }

        $lastKey = array_key_last($entries);
        assert($lastKey !== null);

        return $entries[$lastKey]['value'];
    }

    private function computeDecayWeight(\DateTimeImmutable $solveDate, \DateTimeImmutable $now): float
    {
        $ageInMonths = $this->calculateAgeInMonths($solveDate, $now);
        $effectiveAge = max(0.0, $ageInMonths - self::DECAY_PLATEAU_MONTHS);

        return exp(-$effectiveAge / self::DECAY_RATE_MONTHS);
    }

    private function calculateAgeInMonths(\DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $diff = $from->diff($to);

        return ($diff->y * 12) + $diff->m + ($diff->d / 30.0);
    }
}
