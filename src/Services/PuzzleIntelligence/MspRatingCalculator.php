<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\PlayerRatingPortfolio;
use SpeedPuzzling\Web\Results\RatingEntry;
use SpeedPuzzling\Web\Value\RatingPortfolio;
use Symfony\Contracts\Service\ResetInterface;

/**
 * MSP Rating v2: Portfolio-based snapshot rating with time decay.
 *
 * Evaluates players on their best results from a rolling 24-month window,
 * combining first-attempt and best-time portfolios with difficulty weighting
 * and time decay (3-month plateau, gentle exponential decline). The arithmetic
 * lives in MspRatingFormula; every entry behind a rating is kept
 * (calculatePortfolio(), stored by the batch in player_rating_entry).
 */
final class MspRatingCalculator implements ResetInterface
{
    public const int MINIMUM_FIRST_ATTEMPTS = 20;
    public const int MINIMUM_TOTAL_SOLVES = 50;

    /**
     * Puzzles with this many public first-attempt solvers are loaded too, while they do not count yet: their sorted
     * times are stored for the Insights "pending qualification" what-ifs (docs/features/insights/computation.md).
     */
    public const int NEAR_QUALIFYING_MIN_SOLVERS = 9;

    /**
     * Pre-computed puzzle ranking data for the current recalculation run: every puzzle of the piece count with at
     * least NEAR_QUALIFYING_MIN_SOLVERS public first-attempt solvers; only the qualifying ones count in a rating.
     *
     * @var array<string, array{fa_times: list<int>, bt_times: list<int>, difficulty: float|null, sample_size: int, qualifies: bool}>|null
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
     * Pre-compute puzzle ranking data for all qualifying (and near-qualifying) puzzles.
     * Call once before the per-player loop for efficiency.
     */
    public function precomputePuzzleRankings(int $piecesCount): void
    {
        // Every puzzle with enough first-attempt public solvers; it counts with 20+ of them and a difficulty score
        /** @var list<array{puzzle_id: string, solvers: int|string, difficulty_score: float|string|null, sample_size: int|string|null, confidence: string|null}> $puzzles */
        $puzzles = $this->connection->fetchAllAssociative("
            SELECT solvers.puzzle_id, solvers.solvers, pd.difficulty_score, pd.sample_size, pd.confidence
            FROM (
                SELECT pst.puzzle_id, COUNT(DISTINCT pst.player_id) AS solvers
                FROM puzzle_solving_time pst
                JOIN puzzle p ON p.id = pst.puzzle_id
                JOIN player pl ON pl.id = pst.player_id
                WHERE p.pieces_count = :piecesCount
                    AND pst.first_attempt = true
                    AND pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve IS NOT NULL
                    AND pl.is_private = false
                GROUP BY pst.puzzle_id
                HAVING COUNT(DISTINCT pst.player_id) >= :nearMinSolvers
            ) solvers
            LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = solvers.puzzle_id
        ", [
            'piecesCount' => $piecesCount,
            'nearMinSolvers' => min(self::NEAR_QUALIFYING_MIN_SOLVERS, MspRatingFormula::MIN_SOLVERS_PER_PUZZLE),
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

            $difficulty = $puzzle['difficulty_score'] !== null ? (float) $puzzle['difficulty_score'] : null;

            $cache[$puzzleId] = [
                'fa_times' => $faTimesSorted,
                'bt_times' => $btTimesSorted,
                'difficulty' => $difficulty,
                'sample_size' => (int) $puzzle['sample_size'],
                'qualifies' => (int) $puzzle['solvers'] >= MspRatingFormula::MIN_SOLVERS_PER_PUZZLE
                    && $difficulty !== null
                    && $puzzle['confidence'] !== 'insufficient',
            ];
        }

        $this->puzzleRankingCache = $cache;
    }

    /**
     * The puzzles loaded by precomputePuzzleRankings(): sorted first-attempt and best times of public players, the
     * difficulty, and whether the puzzle counts in ratings.
     *
     * @return array<string, array{fa_times: list<int>, bt_times: list<int>, difficulty: float|null, sample_size: int, qualifies: bool}>
     */
    public function puzzleRankings(): array
    {
        assert($this->puzzleRankingCache !== null, 'Call precomputePuzzleRankings() first');

        return $this->puzzleRankingCache;
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

        return $this->calculatePortfolio($playerId, $piecesCount)->rating;
    }

    /**
     * The rating and every entry behind it. Entries are scored for any player (the rating is 0.0 until the player is
     * eligible and has MINIMUM_FIRST_ATTEMPTS first-attempt entries); each portfolio is sorted by decayed points, best
     * first (ties by puzzle id), and its first CAP entries are the counted ones.
     */
    public function calculatePortfolio(string $playerId, int $piecesCount): PlayerRatingPortfolio
    {
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

        /** @var list<array{puzzle_id: string, seconds: int, solved_at: string, percentile: float, weight: float, decay: float, points: float}> $firstAttemptEntries */
        $firstAttemptEntries = [];
        /** @var list<array{puzzle_id: string, seconds: int, solved_at: string, percentile: float, weight: float, decay: float, points: float}> $bestTimeEntries */
        $bestTimeEntries = [];

        foreach ($playerSolves as $solve) {
            $puzzleId = $solve['puzzle_id'];
            $puzzleData = $this->puzzleRankingCache[$puzzleId] ?? null;

            if ($puzzleData === null || $puzzleData['qualifies'] === false || $puzzleData['difficulty'] === null) {
                continue; // Puzzle doesn't qualify (< 20 solvers or no difficulty)
            }

            $difficultyWeight = MspRatingFormula::difficultyWeight($puzzleData['difficulty'], $puzzleData['sample_size']);

            // Best-time portfolio: any solve in window qualifies, decayed by latest solve date
            $bestTime = (int) $solve['best_time'];
            $btPercentile = MspRatingFormula::percentileInSortedTimes($bestTime, $puzzleData['bt_times']);
            $btDecay = MspRatingFormula::decay(new \DateTimeImmutable($solve['latest_solve_date']), $now);
            $bestTimeEntries[] = [
                'puzzle_id' => $puzzleId,
                'seconds' => $bestTime,
                'solved_at' => $solve['latest_solve_date'],
                'percentile' => $btPercentile,
                'weight' => $difficultyWeight,
                'decay' => $btDecay,
                'points' => $btPercentile * $difficultyWeight * $btDecay,
            ];

            // First-attempt portfolio: first attempt must exist and be in window
            if ($solve['first_attempt_time'] !== null && $solve['first_attempt_date'] !== null) {
                $faDate = new \DateTimeImmutable($solve['first_attempt_date']);

                if ($faDate >= $cutoffDate) {
                    $faTime = (int) $solve['first_attempt_time'];
                    $faPercentile = MspRatingFormula::percentileInSortedTimes($faTime, $puzzleData['fa_times']);
                    $faDecay = MspRatingFormula::decay($faDate, $now);
                    $firstAttemptEntries[] = [
                        'puzzle_id' => $puzzleId,
                        'seconds' => $faTime,
                        'solved_at' => $solve['first_attempt_date'],
                        'percentile' => $faPercentile,
                        'weight' => $difficultyWeight,
                        'decay' => $faDecay,
                        'points' => $faPercentile * $difficultyWeight * $faDecay,
                    ];
                }
            }
        }

        // Build portfolios: sort descending (decayed points), take top CAP
        usort($firstAttemptEntries, self::byPoints(...));
        usort($bestTimeEntries, self::byPoints(...));

        $faTop = array_column(array_slice($firstAttemptEntries, 0, MspRatingFormula::CAP), 'points');
        $btTop = array_column(array_slice($bestTimeEntries, 0, MspRatingFormula::CAP), 'points');

        // Check minimum entry requirements
        $rating = $this->isEligible($playerId, $piecesCount) && count($firstAttemptEntries) >= self::MINIMUM_FIRST_ATTEMPTS
            ? MspRatingFormula::rating($faTop, $btTop)
            : 0.0;

        $entries = [];

        foreach ([[RatingPortfolio::FirstAttempt, $firstAttemptEntries], [RatingPortfolio::BestTime, $bestTimeEntries]] as [$portfolio, $portfolioEntries]) {
            foreach ($portfolioEntries as $position => $entry) {
                $entries[] = new RatingEntry(
                    puzzleId: $entry['puzzle_id'],
                    portfolio: $portfolio,
                    seconds: $entry['seconds'],
                    solvedAt: $entry['solved_at'],
                    percentile: $entry['percentile'],
                    difficultyWeight: $entry['weight'],
                    decay: $entry['decay'],
                    points: $entry['points'],
                    inTop: $position < MspRatingFormula::CAP,
                );
            }
        }

        return new PlayerRatingPortfolio($rating, $entries);
    }

    /**
     * Best entry first; equal points by puzzle id, so which of tied entries is counted never changes between runs
     *
     * @param array{puzzle_id: string, points: float, ...} $a
     * @param array{puzzle_id: string, points: float, ...} $b
     */
    private static function byPoints(array $a, array $b): int
    {
        return $b['points'] <=> $a['points'] ?: strcmp($a['puzzle_id'], $b['puzzle_id']);
    }

    private function getCutoffDate(): \DateTimeImmutable
    {
        return MspRatingFormula::windowStart($this->clock->now());
    }
}
