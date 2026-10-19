<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\ChangedRowsSync;
use SpeedPuzzling\Web\Value\InsightsProfileAxis;

/**
 * Rebuilds player_insights_profile: every player's 7 raw profile-card scores (InsightsProfileCalculator) and their
 * percentiles against every puzzler with a score on that axis. Private players are scored and ranked like everybody
 * (the card is self-only, and an aggregate rank names nobody). Daily: the population moves slowly.
 *
 * Input: solo results that are not suspicious, timed, boxed, on a puzzle that is not secret and has a median solo time
 * from at least MIN_PUZZLE_SOLO_TIMES solo times. Players are loaded in chunks, so memory stays flat.
 */
readonly final class InsightsProfileRecalculator
{
    public const int MIN_PUZZLE_SOLO_TIMES = 5;

    private const int PLAYERS_PER_CHUNK = 500;

    public function __construct(
        private Connection $connection,
        private ChangedRowsSync $sync,
        private InsightsProfileCalculator $calculator,
    ) {
    }

    /**
     * @return array{players: int, written: int, deleted: int}
     */
    public function recalculate(\DateTimeImmutable $now): array
    {
        $learningFactor = $this->learningFactors();
        $nowTimestamp = $now->getTimestamp();

        /** @var array<string, array<string, array{score: null|float, sample_size: int}>> $scores */
        $scores = [];

        foreach (array_chunk($this->playersWithSoloResults(), self::PLAYERS_PER_CHUNK) as $players) {
            $results = $this->loadResults($players[0], $players[count($players) - 1], $now);

            foreach ($results as $playerId => $playerResults) {
                $playerScores = $this->calculator->scores($playerResults, $nowTimestamp, $learningFactor);

                foreach ($playerScores as $axis) {
                    if ($axis['score'] !== null) {
                        $scores[$playerId] = $playerScores;

                        break;
                    }
                }
            }
        }

        $percentiles = $this->percentiles($scores);

        $run = $this->sync->open('player_insights_profile', ['player_id' => 'uuid'], $this->valueColumns());

        foreach ($scores as $playerId => $playerScores) {
            $row = ['player_id' => $playerId];

            foreach (InsightsProfileAxis::cases() as $axis) {
                $score = $playerScores[$axis->value]['score'];
                $row[$axis->value . '_score'] = $score !== null ? round($score, 6) : null;
                $row[$axis->value . '_sample_size'] = $playerScores[$axis->value]['sample_size'];
                $row[$axis->value . '_percentile'] = $percentiles[$axis->value][$playerId] ?? null;
            }

            $run->add($row);
        }

        $written = $run->finish($now);

        return ['players' => count($scores), 'written' => $written['written'], 'deleted' => $written['deleted']];
    }

    /**
     * Percentile = 100 × (puzzlers with a worse score + half of the others with the same score) ÷ (puzzlers − 1):
     * the share of the other puzzlers you are better than, ties shared. Scores are rounded to 6 decimals first, so
     * the same score is a tie however it was summed.
     *
     * @param array<string, array<string, array{score: null|float, sample_size: int}>> $scores
     * @return array<string, array<string, float>> axis => player => percentile
     */
    public function percentiles(array $scores): array
    {
        $percentiles = [];

        foreach (InsightsProfileAxis::cases() as $axis) {
            $oriented = [];

            foreach ($scores as $playerId => $playerScores) {
                $score = $playerScores[$axis->value]['score'] ?? null;

                if ($score !== null) {
                    $rounded = round($score, 6);
                    $oriented[$playerId] = $axis->higherIsBetter() ? $rounded : -$rounded;
                }
            }

            $sorted = array_values($oriented);
            sort($sorted);
            $total = count($sorted);

            foreach ($oriented as $playerId => $value) {
                if ($total <= 1) {
                    $percentiles[$axis->value][(string) $playerId] = 50.0;

                    continue;
                }

                $worse = self::lowerBound($sorted, $value);
                $tiedOthers = self::upperBound($sorted, $value) - $worse - 1;

                $percentiles[$axis->value][(string) $playerId] = round(100.0 * ($worse + $tiedOthers / 2.0) / ($total - 1), 2);
            }
        }

        return $percentiles;
    }

    /**
     * @return array<string, string>
     */
    private function valueColumns(): array
    {
        $columns = [];

        foreach (InsightsProfileAxis::cases() as $axis) {
            $columns[$axis->value . '_score'] = 'double precision';
            $columns[$axis->value . '_sample_size'] = 'integer';
            $columns[$axis->value . '_percentile'] = 'double precision';
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function playersWithSoloResults(): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn("
            SELECT DISTINCT player_id::text
            FROM puzzle_solving_time
            WHERE puzzling_type = 'solo' AND suspicious = false AND seconds_to_solve > 0
            ORDER BY 1
        ");
    }

    /**
     * The players between two ids (inclusive, uuid order = text order of lowercase uuids), each oldest first.
     *
     * @return array<string, list<array{puzzle_id: string, seconds: int, at: int, first_attempt: bool, attempt: int, pieces: int, median: int, tier: null|int, event: bool}>>
     */
    private function loadResults(string $fromPlayer, string $toPlayer, \DateTimeImmutable $now): array
    {
        $rows = $this->connection->executeQuery("
            SELECT ranked.*
            FROM (
                SELECT
                    pst.player_id::text AS player_id,
                    pst.puzzle_id::text AS puzzle_id,
                    pst.seconds_to_solve,
                    EXTRACT(EPOCH FROM COALESCE(pst.finished_at, pst.tracked_at))::bigint AS solved_at,
                    pst.tracked_at,
                    pst.first_attempt,
                    pst.unboxed,
                    ROW_NUMBER() OVER (
                        PARTITION BY pst.player_id, pst.puzzle_id
                        ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at, pst.id
                    ) AS attempt,
                    p.pieces_count,
                    ps.median_time_solo,
                    ps.solved_times_solo_count,
                    pd.difficulty_tier,
                    (pst.competition_id IS NOT NULL OR pst.competition_series_id IS NOT NULL) AS at_event
                FROM puzzle_solving_time pst
                JOIN puzzle p ON p.id = pst.puzzle_id
                LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = pst.puzzle_id
                LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = pst.puzzle_id
                WHERE pst.player_id BETWEEN CAST(:fromPlayer AS uuid) AND CAST(:toPlayer AS uuid)
                    AND pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve > 0
                    AND (p.hide_until IS NULL OR p.hide_until <= :now)
                    AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
            ) ranked
            WHERE ranked.unboxed = false
                AND ranked.median_time_solo > 0
                AND ranked.solved_times_solo_count >= :minSoloTimes
            ORDER BY ranked.player_id, ranked.solved_at, ranked.tracked_at
        ", [
            'fromPlayer' => $fromPlayer,
            'toPlayer' => $toPlayer,
            'now' => $now->format('Y-m-d H:i:s'),
            'minSoloTimes' => self::MIN_PUZZLE_SOLO_TIMES,
        ]);

        $results = [];

        while (($row = $rows->fetchAssociative()) !== false) {
            /** @var array{player_id: string, puzzle_id: string, seconds_to_solve: int|string, solved_at: int|string, first_attempt: bool|int|string, attempt: int|string, pieces_count: int|string, median_time_solo: int|string, difficulty_tier: null|int|string, at_event: bool|int|string} $row */
            $results[$row['player_id']][] = [
                'puzzle_id' => $row['puzzle_id'],
                'seconds' => (int) $row['seconds_to_solve'],
                'at' => (int) $row['solved_at'],
                'first_attempt' => self::bool($row['first_attempt']),
                'attempt' => (int) $row['attempt'],
                'pieces' => (int) $row['pieces_count'],
                'median' => (int) $row['median_time_solo'],
                'tier' => $row['difficulty_tier'] !== null ? (int) $row['difficulty_tier'] : null,
                'event' => self::bool($row['at_event']),
            ];
        }

        return $results;
    }

    /**
     * The community's cumulative learning factor per piece count and attempt (LearningFactors - the one rule, shared
     * with the Insights page).
     *
     * @return callable(int, int): float
     */
    private function learningFactors(): callable
    {
        /** @var list<array{pieces_count: int|string, from_attempt: int|string, median_ratio: float|string, sample_size: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative("
            SELECT pieces_count, from_attempt, median_ratio, sample_size
            FROM global_improvement_ratio
            WHERE gap_bucket = 'all'
        ");

        $ratios = [];

        foreach ($rows as $row) {
            $ratios[(int) $row['pieces_count']][(int) $row['from_attempt']] = ['ratio' => (float) $row['median_ratio'], 'sample_size' => (int) $row['sample_size']];
        }

        return LearningFactors::fromGlobalRatios($ratios)->factor(...);
    }

    private static function bool(bool|int|string $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    /**
     * @param list<float> $sorted
     */
    private static function lowerBound(array $sorted, float $value): int
    {
        $low = 0;
        $high = count($sorted);

        while ($low < $high) {
            $middle = ($low + $high) >> 1;

            if ($sorted[$middle] < $value) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * @param list<float> $sorted
     */
    private static function upperBound(array $sorted, float $value): int
    {
        $low = 0;
        $high = count($sorted);

        while ($low < $high) {
            $middle = ($low + $high) >> 1;

            if ($sorted[$middle] <= $value) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }
}
