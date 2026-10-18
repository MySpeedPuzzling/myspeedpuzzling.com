<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\RepeatResiduals;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Time predictions of one player at list scale (puzzle picker cards and
 * filters, collection pages). Same math as GetPlayerPrediction::forPuzzle() —
 * both delegate to TimePredictionCalculator — but the inputs are loaded in bulk:
 * the player's attempts on every puzzle in one query, the player's ratios with
 * their repeat residuals and the fitted model parameters in one, the global
 * ratios in one, and the statistical inputs (StatisticalInputsSql) for the asked
 * puzzle ids in one more. The per-player part is memoised for the request (the
 * picker query and the card rendering both need it), reset() clears it —
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

    /**
     * The fitted model parameters, from the ratio statement of a personal pass.
     *
     * @var array<string, PredictionModelParameters>
     */
    private array $parametersByPlayer = [];

    public function __construct(
        private readonly Connection $database,
        private readonly ClockInterface $clock,
        private readonly TimePredictionCalculator $calculator,
    ) {
    }

    public function reset(): void
    {
        $this->personalByPlayer = [];
        $this->parametersByPlayer = [];
    }

    /**
     * The model parameters the player's predictions were made with - read by the personal pass; a player without
     * any solo solve has no pace either, so nothing of theirs depends on them (defaults, no query).
     */
    public function modelParameters(string $playerId): PredictionModelParameters
    {
        $this->forAllSolvedPuzzles($playerId);

        return $this->parametersByPlayer[$playerId] ?? PredictionModelParameters::defaults();
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

        // No solo solve = no pace at any piece count: nothing statistical to predict
        if ($missing !== [] && $personal !== []) {
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

        [$playerRatios, $repeatSpread, $parameters] = $this->playerRatiosAndRepeats($playerId);
        $this->parametersByPlayer[$playerId] = $parameters;
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
                $repeatSpread,
                $attempt['pieces_count'],
                $parameters,
            );
        }

        return $predictions;
    }

    /**
     * The player's ratios, the spread of their stored repeat predictions and the fitted model parameters - one
     * statement, one row.
     *
     * @return array{0: array<int, float>, 1: float, 2: PredictionModelParameters} transition → median ratio, spread, parameters
     */
    private function playerRatiosAndRepeats(string $playerId): array
    {
        $repeats = RepeatResiduals::sql(excludeTime: false, before: false);
        $parameters = PredictionModelParametersProvider::SQL;

        /** @var array{ratios: null|string, repeats: null|string, parameters: null|string} $row */
        $row = $this->database->fetchAssociative("
            SELECT
                (SELECT json_object_agg(from_attempt, median_ratio) FROM player_improvement_ratio WHERE player_id = :playerId) AS ratios,
                ({$repeats}) AS repeats,
                ({$parameters}) AS parameters
        ", ['playerId' => $playerId, 'modelVersion' => TimePredictionCalculator::MODEL_VERSION]);

        $ratios = [];

        foreach ($row['ratios'] !== null ? (array) json_decode($row['ratios'], true, flags: JSON_THROW_ON_ERROR) : [] as $fromAttempt => $ratio) {
            if (is_numeric($ratio)) {
                $ratios[(int) $fromAttempt] = (float) $ratio;
            }
        }

        return [
            $ratios,
            TimePredictionCalculator::playerSpread(RepeatResiduals::fromJson($row['repeats'])),
            PredictionModelParametersProvider::fromJson($row['parameters']),
        ];
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
        $cte = StatisticalInputsSql::ownPaceCte();
        $columns = StatisticalInputsSql::COLUMNS;
        $joins = StatisticalInputsSql::JOINS . StatisticalInputsSql::ownPaceCteJoins();

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database->executeQuery(
            "WITH {$cte} SELECT {$columns} FROM puzzle p {$joins} WHERE p.id IN (:puzzleIds)",
            ['playerId' => $playerId, 'puzzleIds' => $puzzleIds],
            ['puzzleIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $parameters = $this->modelParameters($playerId);
        $predictions = [];

        foreach ($rows as $row) {
            $prediction = $this->calculator->statistical(StatisticalInputsSql::hydrate($row), $parameters);

            if ($prediction !== null && is_string($row['puzzle_id'])) {
                $predictions[$row['puzzle_id']] = $prediction;
            }
        }

        return $predictions;
    }
}
