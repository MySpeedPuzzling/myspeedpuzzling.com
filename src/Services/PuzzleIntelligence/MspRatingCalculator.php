<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * MSP Rating v2: Portfolio-based snapshot rating with time decay.
 *
 * Evaluates players on their best results from a rolling 24-month window,
 * combining first-attempt and best-time portfolios with difficulty weighting
 * and time decay (3-month plateau, gentle exponential decline).
 */
final class MspRatingCalculator implements ResetInterface
{
    public const int MINIMUM_FIRST_ATTEMPTS = 20;
    public const int MINIMUM_TOTAL_SOLVES = 50;

    private const int CAP = 100;
    private const int WINDOW_MONTHS = 24;
    private const float DECAY_PLATEAU_MONTHS = 3.0;
    private const float DECAY_RATE_MONTHS = 30.0;
    private const int MIN_SOLVERS_PER_PUZZLE = 20;
    private const float DIFFICULTY_BLEND = 0.5;
    private const int DIFF_CONFIDENCE_THRESHOLD = 50;
    private const float FIRST_ATTEMPT_WEIGHT = 0.75;

    /**
     * Pre-computed puzzle ranking data for the current recalculation run.
     *
     * @var array<string, array{fa_times: list<int>, bt_times: list<int>, difficulty: float, sample_size: int}>|null
     */
    private null|array $puzzleRankingCache = null;

    /**
     * Pre-computed per-player solve data: puzzle_id => {first_attempt_time, first_attempt_date, best_time, latest_solve_date}.
     *
     * @var array<string, list<array{puzzle_id: string, first_attempt_time: int|null, first_attempt_date: string|null, best_time: int, latest_solve_date: string}>>|null
     */
    private null|array $playerSolvesCache = null;

    /**
     * Pre-computed eligibility data per player.
     *
     * @var array<string, array{first_attempts: int, total_solves: int}>|null
     */
    private null|array $playerProgressCache = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Pre-compute puzzle ranking data for all qualifying puzzles.
     * Call once before the per-player loop for efficiency.
     */
    public function precomputePuzzleRankings(int $piecesCount): void
    {
        // Get all puzzles with 20+ first-attempt public solvers and a difficulty score
        /** @var list<array{puzzle_id: string, difficulty_score: float|string, sample_size: int|string}> $puzzles */
        $puzzles = $this->connection->fetchAllAssociative("
            SELECT pd.puzzle_id, pd.difficulty_score, pd.sample_size
            FROM puzzle_difficulty pd
            JOIN puzzle p ON p.id = pd.puzzle_id
            WHERE p.pieces_count = :piecesCount
                AND pd.difficulty_score IS NOT NULL
                AND pd.confidence != 'insufficient'
                AND (
                    SELECT COUNT(DISTINCT pst.player_id)
                    FROM puzzle_solving_time pst
                    JOIN player pl ON pl.id = pst.player_id
                    WHERE pst.puzzle_id = pd.puzzle_id
                        AND pst.first_attempt = true
                        AND pst.puzzling_type = 'solo'
                        AND pst.suspicious = false
                        AND pst.seconds_to_solve IS NOT NULL
                        AND pl.is_private = false
                ) >= :minSolvers
        ", [
            'piecesCount' => $piecesCount,
            'minSolvers' => self::MIN_SOLVERS_PER_PUZZLE,
        ]);

        if ($puzzles === []) {
            $this->puzzleRankingCache = [];

            return;
        }

        $puzzleIds = array_column($puzzles, 'puzzle_id');

        // Bulk first-attempt times (public players only, one per player per puzzle)
        // Ordered by solved_at ASC so the dedup below keeps the earliest row per player — matches DISTINCT ON behavior
        /** @var list<array{puzzle_id: string, player_id: string, seconds_to_solve: int|string}> $faRows */
        $faRows = $this->connection->fetchAllAssociative("
            SELECT pst.puzzle_id, pst.player_id, pst.seconds_to_solve
            FROM puzzle_solving_time pst
            JOIN player pl ON pl.id = pst.player_id
            WHERE pst.puzzle_id IN (:puzzleIds)
                AND pst.first_attempt = true
                AND pst.puzzling_type = 'solo'
                AND pst.suspicious = false
                AND pst.seconds_to_solve IS NOT NULL
                AND pl.is_private = false
            ORDER BY pst.puzzle_id, pst.player_id, COALESCE(pst.finished_at, pst.tracked_at) ASC, pst.tracked_at ASC
        ", ['puzzleIds' => $puzzleIds], ['puzzleIds' => ArrayParameterType::STRING]);

        // Deduplicate: keep first row per (puzzle_id, player_id) — matches DISTINCT ON behavior
        $faPerPuzzle = [];

        foreach ($faRows as $row) {
            $puzzleId = $row['puzzle_id'];
            $playerId = $row['player_id'];

            if (!isset($faPerPuzzle[$puzzleId][$playerId])) {
                $faPerPuzzle[$puzzleId][$playerId] = (int) $row['seconds_to_solve'];
            }
        }

        // Bulk best times per player per puzzle (public players only)
        /** @var list<array{puzzle_id: string, best_time: int|string}> $btRows */
        $btRows = $this->connection->fetchAllAssociative("
            SELECT pst.puzzle_id, MIN(pst.seconds_to_solve) AS best_time
            FROM puzzle_solving_time pst
            JOIN player pl ON pl.id = pst.player_id
            WHERE pst.puzzle_id IN (:puzzleIds)
                AND pst.puzzling_type = 'solo'
                AND pst.suspicious = false
                AND pst.seconds_to_solve IS NOT NULL
                AND pl.is_private = false
            GROUP BY pst.puzzle_id, pst.player_id
        ", ['puzzleIds' => $puzzleIds], ['puzzleIds' => ArrayParameterType::STRING]);

        $btPerPuzzle = [];

        foreach ($btRows as $row) {
            $btPerPuzzle[$row['puzzle_id']][] = (int) $row['best_time'];
        }

        // Build cache
        $cache = [];

        foreach ($puzzles as $puzzle) {
            $puzzleId = $puzzle['puzzle_id'];

            $faTimesSorted = array_values($faPerPuzzle[$puzzleId] ?? []);
            $btTimesSorted = $btPerPuzzle[$puzzleId] ?? [];
            sort($faTimesSorted);
            sort($btTimesSorted);

            $cache[$puzzleId] = [
                'fa_times' => $faTimesSorted,
                'bt_times' => $btTimesSorted,
                'difficulty' => (float) $puzzle['difficulty_score'],
                'sample_size' => (int) $puzzle['sample_size'],
            ];
        }

        $this->puzzleRankingCache = $cache;
    }

    /**
     * Bulk-load all player solve data for a piece count in one query.
     * Call once before the per-player loop.
     */
    public function preloadAllPlayerSolves(int $piecesCount): void
    {
        $cutoffDate = $this->getCutoffDate();

        /** @var list<array{player_id: string, puzzle_id: string, first_attempt_time: int|string|null, first_attempt_date: string|null, best_time: int|string, latest_solve_date: string}> $rows */
        $rows = $this->connection->fetchAllAssociative("
            SELECT
                pst.player_id,
                pst.puzzle_id,
                MIN(CASE WHEN pst.first_attempt = true THEN pst.seconds_to_solve END) AS first_attempt_time,
                MIN(CASE WHEN pst.first_attempt = true THEN COALESCE(pst.finished_at, pst.tracked_at)::text END) AS first_attempt_date,
                MIN(pst.seconds_to_solve) AS best_time,
                MAX(COALESCE(pst.finished_at, pst.tracked_at))::text AS latest_solve_date
            FROM puzzle_solving_time pst
            JOIN puzzle p ON p.id = pst.puzzle_id
            JOIN player pl ON pl.id = pst.player_id
            WHERE p.pieces_count = :piecesCount
                AND pst.puzzling_type = 'solo'
                AND pst.suspicious = false
                AND pst.seconds_to_solve IS NOT NULL
                AND pl.is_private = false
            GROUP BY pst.player_id, pst.puzzle_id
            HAVING MAX(COALESCE(pst.finished_at, pst.tracked_at)) >= :cutoff
        ", [
            'piecesCount' => $piecesCount,
            'cutoff' => $cutoffDate->format('Y-m-d H:i:s'),
        ]);

        $solvesCache = [];
        $progressCache = [];

        foreach ($rows as $row) {
            $playerId = $row['player_id'];
            $faTime = $row['first_attempt_time'] !== null ? (int) $row['first_attempt_time'] : null;
            $faDate = $row['first_attempt_date'];

            $solvesCache[$playerId][] = [
                'puzzle_id' => $row['puzzle_id'],
                'first_attempt_time' => $faTime,
                'first_attempt_date' => $faDate,
                'best_time' => (int) $row['best_time'],
                'latest_solve_date' => $row['latest_solve_date'],
            ];

            // Compute progress
            if (!isset($progressCache[$playerId])) {
                $progressCache[$playerId] = ['first_attempts' => 0, 'total_solves' => 0];
            }

            $progressCache[$playerId]['total_solves']++;

            if ($faTime !== null && $faDate !== null) {
                $faDateObj = new \DateTimeImmutable($faDate);

                if ($faDateObj >= $cutoffDate) {
                    $progressCache[$playerId]['first_attempts']++;
                }
            }
        }

        $this->playerSolvesCache = $solvesCache;
        $this->playerProgressCache = $progressCache;
    }

    /**
     * Clear all pre-computed caches (call after recalculation).
     */
    public function clearCache(): void
    {
        $this->puzzleRankingCache = null;
        $this->playerSolvesCache = null;
        $this->playerProgressCache = null;
    }

    public function reset(): void
    {
        $this->clearCache();
    }

    public function isEligible(string $playerId, int $piecesCount): bool
    {
        $progress = $this->getProgress($playerId, $piecesCount);

        return $progress['first_attempts'] >= self::MINIMUM_FIRST_ATTEMPTS
            && $progress['total_solves'] >= self::MINIMUM_TOTAL_SOLVES;
    }

    /**
     * @return array{first_attempts: int, total_solves: int}
     */
    public function getProgress(string $playerId, int $piecesCount): array
    {
        if ($this->playerProgressCache !== null) {
            return $this->playerProgressCache[$playerId] ?? ['first_attempts' => 0, 'total_solves' => 0];
        }

        $cutoffDate = $this->getCutoffDate();

        /** @var array{first_attempt_count: int|string, total_count: int|string}|false $result */
        $result = $this->connection->fetchAssociative("
            SELECT
                COUNT(DISTINCT pst.puzzle_id) FILTER (WHERE pst.first_attempt = true AND COALESCE(pst.finished_at, pst.tracked_at) >= :cutoff) AS first_attempt_count,
                COUNT(DISTINCT pst.puzzle_id) FILTER (WHERE COALESCE(pst.finished_at, pst.tracked_at) >= :cutoff) AS total_count
            FROM puzzle_solving_time pst
            JOIN puzzle p ON p.id = pst.puzzle_id
            WHERE pst.player_id = :playerId
                AND p.pieces_count = :piecesCount
                AND pst.puzzling_type = 'solo'
                AND pst.suspicious = false
                AND pst.seconds_to_solve IS NOT NULL
        ", [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
            'cutoff' => $cutoffDate->format('Y-m-d H:i:s'),
        ]);

        return [
            'first_attempts' => $result !== false ? (int) $result['first_attempt_count'] : 0,
            'total_solves' => $result !== false ? (int) $result['total_count'] : 0,
        ];
    }

    /**
     * Calculate portfolio-based rating for a player.
     * Portfolio entries are decayed by age before sorting and selection.
     */
    public function calculateForPlayer(string $playerId, int $piecesCount): float
    {
        if (!$this->isEligible($playerId, $piecesCount)) {
            return 0.0;
        }

        assert($this->puzzleRankingCache !== null, 'Call precomputePuzzleRankings() before calculateForPlayer()');

        $cutoffDate = $this->getCutoffDate();
        $now = $this->clock->now();

        if ($this->playerSolvesCache !== null) {
            $playerSolves = $this->playerSolvesCache[$playerId] ?? [];
        } else {
            /** @var list<array{puzzle_id: string, first_attempt_time: int|string|null, first_attempt_date: string|null, best_time: int|string, latest_solve_date: string}> $playerSolves */
            $playerSolves = $this->connection->fetchAllAssociative("
                SELECT
                    pst.puzzle_id,
                    MIN(CASE WHEN pst.first_attempt = true THEN pst.seconds_to_solve END) AS first_attempt_time,
                    MIN(CASE WHEN pst.first_attempt = true THEN COALESCE(pst.finished_at, pst.tracked_at)::text END) AS first_attempt_date,
                    MIN(pst.seconds_to_solve) AS best_time,
                    MAX(COALESCE(pst.finished_at, pst.tracked_at))::text AS latest_solve_date
                FROM puzzle_solving_time pst
                JOIN puzzle p ON p.id = pst.puzzle_id
                WHERE pst.player_id = :playerId
                    AND p.pieces_count = :piecesCount
                    AND pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve IS NOT NULL
                GROUP BY pst.puzzle_id
                HAVING MAX(COALESCE(pst.finished_at, pst.tracked_at)) >= :cutoff
            ", [
                'playerId' => $playerId,
                'piecesCount' => $piecesCount,
                'cutoff' => $cutoffDate->format('Y-m-d H:i:s'),
            ]);
        }

        $firstAttemptEntries = [];
        $bestTimeEntries = [];

        foreach ($playerSolves as $solve) {
            $puzzleId = $solve['puzzle_id'];

            if (!isset($this->puzzleRankingCache[$puzzleId])) {
                continue; // Puzzle doesn't qualify (< 20 solvers or no difficulty)
            }

            $puzzleData = $this->puzzleRankingCache[$puzzleId];
            $difficultyWeight = $this->computeDifficultyWeight($puzzleData['difficulty'], $puzzleData['sample_size']);

            // Best-time portfolio: any solve in window qualifies, decayed by latest solve date
            $bestTime = (int) $solve['best_time'];
            $btPercentile = $this->computePercentileInSortedArray($bestTime, $puzzleData['bt_times']);
            $latestSolveDate = new \DateTimeImmutable($solve['latest_solve_date']);
            $btDecay = $this->computeDecayWeight($latestSolveDate, $now);
            $bestTimeEntries[] = $btPercentile * $difficultyWeight * $btDecay;

            // First-attempt portfolio: first attempt must exist and be in window
            if ($solve['first_attempt_time'] !== null && $solve['first_attempt_date'] !== null) {
                $faDate = new \DateTimeImmutable($solve['first_attempt_date']);

                if ($faDate >= $cutoffDate) {
                    $faTime = (int) $solve['first_attempt_time'];
                    $faPercentile = $this->computePercentileInSortedArray($faTime, $puzzleData['fa_times']);
                    $faDecay = $this->computeDecayWeight($faDate, $now);
                    $firstAttemptEntries[] = $faPercentile * $difficultyWeight * $faDecay;
                }
            }
        }

        // Check minimum entry requirements
        if (count($firstAttemptEntries) < self::MINIMUM_FIRST_ATTEMPTS) {
            return 0.0;
        }

        // Build portfolios: sort descending (decayed points), take top CAP
        rsort($firstAttemptEntries);
        rsort($bestTimeEntries);

        $faTop = array_slice($firstAttemptEntries, 0, self::CAP);
        $btTop = array_slice($bestTimeEntries, 0, self::CAP);

        $faScore = array_sum($faTop) / count($faTop);
        $btScore = $btTop !== [] ? array_sum($btTop) / count($btTop) : 0.0;

        return self::FIRST_ATTEMPT_WEIGHT * $faScore + (1.0 - self::FIRST_ATTEMPT_WEIGHT) * $btScore;
    }

    /**
     * Compute percentile of a time in a sorted array using average rank method.
     * Returns 0.0 (slowest) to 1.0 (fastest).
     *
     * @param list<int> $sortedTimes ascending
     */
    private function computePercentileInSortedArray(int $playerTime, array $sortedTimes): float
    {
        $total = count($sortedTimes);

        if ($total <= 1) {
            return 0.5;
        }

        $slowerCount = 0;
        $tiedCount = 0;

        foreach ($sortedTimes as $time) {
            if ($time > $playerTime) {
                $slowerCount++;
            } elseif ($time === $playerTime) {
                $tiedCount++;
            }
        }

        // Exclude self from tied count (player is one of the tied entries)
        $tiedOthers = max(0, $tiedCount - 1);

        return ($slowerCount + $tiedOthers / 2.0) / ($total - 1);
    }

    private function computeDifficultyWeight(float $difficulty, int $sampleSize): float
    {
        $confidence = min(1.0, $sampleSize / self::DIFF_CONFIDENCE_THRESHOLD);
        $blend = self::DIFFICULTY_BLEND * $confidence;

        return (1.0 - $blend) + $blend * $difficulty;
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

    private function getCutoffDate(): \DateTimeImmutable
    {
        return $this->clock->now()->modify('-' . self::WINDOW_MONTHS . ' months');
    }
}
