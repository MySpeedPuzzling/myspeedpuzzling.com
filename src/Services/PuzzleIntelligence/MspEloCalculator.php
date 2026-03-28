<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;

readonly final class MspEloCalculator
{
    private const int MINIMUM_FIRST_ATTEMPTS = 15;
    private const int MINIMUM_TOTAL_SOLVES = 50;
    private const int STARTING_ELO = 1000;
    private const int K_FACTOR_PLACEMENT = 60;
    private const int K_FACTOR_ESTABLISHED = 30;
    private const int PLACEMENT_MATCHES = 10;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function isEligible(string $playerId, int $piecesCount): bool
    {
        $result = $this->connection->fetchAssociative("
            SELECT
                COUNT(*) FILTER (WHERE first_attempt = true) AS first_attempt_count,
                COUNT(*) AS total_count
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
        ]);

        /** @var array{first_attempt_count: int|string, total_count: int|string}|false $result */
        if ($result === false) {
            return false;
        }

        return (int) $result['first_attempt_count'] >= self::MINIMUM_FIRST_ATTEMPTS
            && (int) $result['total_count'] >= self::MINIMUM_TOTAL_SOLVES;
    }

    /**
     * @return array{first_attempts: int, total_solves: int}
     */
    public function getProgress(string $playerId, int $piecesCount): array
    {
        /** @var array{first_attempt_count: int|string, total_count: int|string}|false $result */
        $result = $this->connection->fetchAssociative("
            SELECT
                COUNT(*) FILTER (WHERE first_attempt = true) AS first_attempt_count,
                COUNT(*) AS total_count
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
        ]);

        return [
            'first_attempts' => $result !== false ? (int) $result['first_attempt_count'] : 0,
            'total_solves' => $result !== false ? (int) $result['total_count'] : 0,
        ];
    }

    /**
     * Calculate full ELO rating for a player by replaying all their solves.
     * Used during batch recalculation.
     */
    public function calculateForPlayer(string $playerId, int $piecesCount, string $period): int
    {
        if (!$this->isEligible($playerId, $piecesCount)) {
            return self::STARTING_ELO;
        }

        $periodCondition = '';

        if ($period !== 'all-time') {
            // Period is in YYYY-MM format
            $periodCondition = "AND DATE_TRUNC('month', COALESCE(pst.finished_at, pst.tracked_at)) = :period::date";
        }

        // Get first-attempt solo solves for this player, ordered by date
        $sql = "
            SELECT
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
                AND pst.first_attempt = true
                {$periodCondition}
            ORDER BY COALESCE(pst.finished_at, pst.tracked_at) ASC
        ";

        $params = [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
        ];

        if ($period !== 'all-time') {
            $params['period'] = $period . '-01';
        }

        /** @var list<array{puzzle_id: string, seconds_to_solve: int|string, solve_date: string}> $solves */
        $solves = $this->connection->fetchAllAssociative($sql, $params);

        $elo = self::STARTING_ELO;
        $matchCount = 0;

        foreach ($solves as $solve) {
            $puzzleId = $solve['puzzle_id'];
            $solveTime = (int) $solve['seconds_to_solve'];

            $percentile = $this->getPercentileOnPuzzle($puzzleId, $solveTime, $piecesCount);

            if ($percentile === null) {
                continue;
            }

            // Expected percentile based on current ELO vs average pool ELO
            $avgPoolElo = $this->getAveragePoolElo($puzzleId, $piecesCount, $period);
            $expectedPercentile = 1.0 / (1.0 + (10.0 ** (($avgPoolElo - $elo) / 400.0)));

            $kFactor = $matchCount < self::PLACEMENT_MATCHES ? self::K_FACTOR_PLACEMENT : self::K_FACTOR_ESTABLISHED;
            $elo += (int) round($kFactor * ($percentile - $expectedPercentile));

            $matchCount++;
        }

        return $elo;
    }

    /**
     * Get player's percentile rank on a specific puzzle (0.0 to 1.0).
     * Only compares against first-attempt solves.
     */
    private function getPercentileOnPuzzle(string $puzzleId, int $solveTime, int $piecesCount): null|float
    {
        $result = $this->connection->fetchAssociative("
            SELECT
                COUNT(*) FILTER (WHERE seconds_to_solve > :solveTime) AS slower,
                COUNT(*) AS total
            FROM puzzle_solving_time pst
            WHERE pst.puzzle_id = :puzzleId
                AND pst.puzzling_type = 'solo'
                AND pst.suspicious = false
                AND pst.seconds_to_solve IS NOT NULL
                AND pst.first_attempt = true
        ", [
            'puzzleId' => $puzzleId,
            'solveTime' => $solveTime,
        ]);

        /** @var array{slower: int|string, total: int|string}|false $result */
        if ($result === false || (int) $result['total'] <= 1) {
            return null;
        }

        return (int) $result['slower'] / ((int) $result['total'] - 1);
    }

    private function getAveragePoolElo(string $puzzleId, int $piecesCount, string $period): float
    {
        /** @var array{avg_elo: float|string|null}|false $result */
        $result = $this->connection->fetchAssociative("
            SELECT AVG(pe.elo_rating) AS avg_elo
            FROM player_elo pe
            WHERE pe.pieces_count = :piecesCount
                AND pe.period = :period
        ", [
            'piecesCount' => $piecesCount,
            'period' => $period,
        ]);

        if ($result === false || $result['avg_elo'] === null) {
            return (float) self::STARTING_ELO;
        }

        return (float) $result['avg_elo'];
    }
}
