<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Time predictions of one player at list scale (puzzle picker cards and
 * filters, collection pages). Same math as GetPlayerPrediction::forPuzzle() —
 * both delegate to TimePredictionCalculator — but the inputs are loaded in bulk:
 * the player's attempts on every puzzle in one query, the two ratio tables in
 * one query each, and the statistical inputs (baseline × difficulty) for the
 * asked puzzle ids in one more. The per-player part is memoised for the request
 * (the picker query and the card rendering both need it), reset() clears it —
 * FrankenPHP worker mode keeps the service instance alive between requests.
 */
final class GetPlayerPredictions implements ResetInterface
{
    /**
     * Personal predictions per player: puzzle id → prediction, for every puzzle
     * the player has at least one solo, valid, non-unboxed attempt on.
     *
     * @var array<string, array<string, TimePredictionResult>>
     */
    private array $personalByPlayer = [];

    public function __construct(
        private readonly Connection $database,
        private readonly ClockInterface $clock,
        private readonly TimePredictionCalculator $calculator,
    ) {
    }

    public function reset(): void
    {
        $this->personalByPlayer = [];
    }

    /**
     * Prediction for each of the given puzzles: personal where the player has
     * solved the puzzle before, statistical where the player's baseline and the
     * puzzle's difficulty exist, absent otherwise.
     *
     * @param list<string> $puzzleIds
     *
     * @return array<string, TimePredictionResult> keyed by puzzle id
     */
    public function forPuzzles(string $playerId, array $puzzleIds): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $personal = $this->forAllSolvedPuzzles($playerId);
        $predictions = [];
        $missing = [];

        foreach ($puzzleIds as $puzzleId) {
            if (isset($personal[$puzzleId])) {
                $predictions[$puzzleId] = $personal[$puzzleId];
            } else {
                $missing[] = $puzzleId;
            }
        }

        if ($missing !== []) {
            foreach ($this->statisticalPredictions($playerId, $missing) as $puzzleId => $prediction) {
                $predictions[$puzzleId] = $prediction;
            }
        }

        return $predictions;
    }

    /**
     * Personal predictions for every puzzle the player has solo attempts on.
     *
     * @return array<string, TimePredictionResult> keyed by puzzle id
     */
    public function forAllSolvedPuzzles(string $playerId): array
    {
        if (isset($this->personalByPlayer[$playerId])) {
            return $this->personalByPlayer[$playerId];
        }

        return $this->personalByPlayer[$playerId] = $this->personalPredictions($playerId);
    }

    /**
     * @return array<string, TimePredictionResult>
     */
    private function personalPredictions(string $playerId): array
    {
        $query = <<<SQL
SELECT
    pst.puzzle_id,
    p.pieces_count,
    pst.seconds_to_solve,
    COALESCE(pst.finished_at, pst.tracked_at) AS solved_at
FROM puzzle_solving_time pst
JOIN puzzle p ON p.id = pst.puzzle_id
WHERE pst.player_id = :playerId
    AND pst.puzzling_type = 'solo'
    AND pst.suspicious = false
    AND pst.seconds_to_solve IS NOT NULL
    AND pst.unboxed = false
ORDER BY pst.puzzle_id, COALESCE(pst.finished_at, pst.tracked_at) ASC, pst.tracked_at ASC
SQL;

        /** @var list<array{puzzle_id: string, pieces_count: int|string, seconds_to_solve: int|string, solved_at: string}> $rows */
        $rows = $this->database->executeQuery($query, ['playerId' => $playerId])->fetchAllAssociative();

        if ($rows === []) {
            return [];
        }

        /** @var array<string, array{pieces_count: int, times: non-empty-list<int>, last_solved_at: string}> $attempts */
        $attempts = [];

        foreach ($rows as $row) {
            $puzzleId = $row['puzzle_id'];

            if (isset($attempts[$puzzleId])) {
                $attempts[$puzzleId]['times'][] = (int) $row['seconds_to_solve'];
                $attempts[$puzzleId]['last_solved_at'] = $row['solved_at'];
            } else {
                $attempts[$puzzleId] = [
                    'pieces_count' => (int) $row['pieces_count'],
                    'times' => [(int) $row['seconds_to_solve']],
                    'last_solved_at' => $row['solved_at'],
                ];
            }
        }

        $playerRatios = $this->playerRatios($playerId);
        $globalRatios = $this->globalRatios(array_values(array_unique(array_column($attempts, 'pieces_count'))));
        $now = $this->clock->now();
        $predictions = [];

        foreach ($attempts as $puzzleId => $attempt) {
            $transition = TimePredictionCalculator::transitionFor(count($attempt['times']));
            $gapBucket = TimePredictionCalculator::classifyGap(
                TimePredictionCalculator::gapDays($now, new DateTimeImmutable($attempt['last_solved_at'])),
            );

            $playerRatio = $playerRatios[$transition] ?? null;
            $globalRatioForGap = $globalRatios[$attempt['pieces_count']][$transition][$gapBucket] ?? null;
            $globalRatioAll = $globalRatios[$attempt['pieces_count']][$transition]['all'] ?? null;

            $predictions[$puzzleId] = $this->calculator->personal(
                $attempt['times'],
                TimePredictionCalculator::resolveRatio($playerRatio, $globalRatioForGap, $globalRatioAll),
            );
        }

        return $predictions;
    }

    /**
     * @return array<int, float> transition → median ratio
     */
    private function playerRatios(string $playerId): array
    {
        /** @var list<array{from_attempt: int|string, median_ratio: float|string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT from_attempt, median_ratio FROM player_improvement_ratio WHERE player_id = :playerId',
            ['playerId' => $playerId],
        )->fetchAllAssociative();

        $ratios = [];

        foreach ($rows as $row) {
            $ratios[(int) $row['from_attempt']] = (float) $row['median_ratio'];
        }

        return $ratios;
    }

    /**
     * @param list<int> $piecesCounts
     *
     * @return array<int, array<int, array<string, float>>> pieces count → transition → gap bucket → median ratio
     */
    private function globalRatios(array $piecesCounts): array
    {
        if ($piecesCounts === []) {
            return [];
        }

        /** @var list<array{pieces_count: int|string, from_attempt: int|string, gap_bucket: string, median_ratio: float|string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT pieces_count, from_attempt, gap_bucket, median_ratio FROM global_improvement_ratio WHERE pieces_count IN (:piecesCounts)',
            ['piecesCounts' => $piecesCounts],
            ['piecesCounts' => ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $ratios = [];

        foreach ($rows as $row) {
            $ratios[(int) $row['pieces_count']][(int) $row['from_attempt']][$row['gap_bucket']] = (float) $row['median_ratio'];
        }

        return $ratios;
    }

    /**
     * @param non-empty-list<string> $puzzleIds
     *
     * @return array<string, TimePredictionResult>
     */
    private function statisticalPredictions(string $playerId, array $puzzleIds): array
    {
        $query = <<<SQL
SELECT
    p.id AS puzzle_id,
    pb.baseline_seconds,
    pd.difficulty_score,
    pd.indices_p25,
    pd.indices_p75
FROM puzzle p
JOIN puzzle_difficulty pd ON pd.puzzle_id = p.id
JOIN player_baseline pb ON pb.player_id = :playerId AND pb.pieces_count = p.pieces_count
WHERE p.id IN (:puzzleIds)
    AND pd.difficulty_score IS NOT NULL
    AND pd.confidence != 'insufficient'
SQL;

        /** @var list<array{puzzle_id: string, baseline_seconds: int|string, difficulty_score: float|string, indices_p25: float|string|null, indices_p75: float|string|null}> $rows */
        $rows = $this->database->executeQuery(
            $query,
            ['playerId' => $playerId, 'puzzleIds' => $puzzleIds],
            ['puzzleIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $predictions = [];

        foreach ($rows as $row) {
            $predictions[$row['puzzle_id']] = $this->calculator->statistical(
                baselineSeconds: (int) $row['baseline_seconds'],
                difficultyScore: (float) $row['difficulty_score'],
                p25: $row['indices_p25'] !== null ? (float) $row['indices_p25'] : null,
                p75: $row['indices_p75'] !== null ? (float) $row['indices_p75'] : null,
            );
        }

        return $predictions;
    }
}
