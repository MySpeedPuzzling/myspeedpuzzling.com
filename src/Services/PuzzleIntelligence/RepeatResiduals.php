<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use SpeedPuzzling\Web\Value\PredictionInputSolve;
use SpeedPuzzling\Web\Value\ShownPredictionModel;

/**
 * The residuals ln(actual ÷ predicted) of a player's last 20 repeats with a stored personal prediction - the spread a
 * repeat's window rests on (TimePredictionCalculator::playerSpread()). The centre of a repeat (Holt) did not change
 * between model versions, so every stored personal prediction counts. Read live as a scalar subquery of a statement
 * that runs anyway (sql()), and by PredictionReconstructor from the player's solves (beforeEverySolve()) - the same rows.
 */
final class RepeatResiduals
{
    public const int WINDOW = 20;

    /**
     * Expects :playerId, and :excludeTimeId / :solvedAt + :trackedAt when asked for.
     */
    public static function sql(bool $excludeTime, bool $before): string
    {
        $exclude = $excludeTime ? 'AND pst.id != :excludeTimeId' : '';
        $beforeCondition = $before
            ? 'AND (COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at) < (CAST(:solvedAt AS timestamp), CAST(:trackedAt AS timestamp))'
            : '';
        $window = self::WINDOW;
        $stored = self::storedPersonalSql('pst');

        return "
            SELECT json_agg(json_build_array(r.seconds_to_solve, r.predicted_seconds) ORDER BY r.solved_at, r.tracked_at, r.id)
            FROM (
                SELECT pst.id, pst.seconds_to_solve, {$stored} AS predicted_seconds, COALESCE(pst.finished_at, pst.tracked_at) AS solved_at, pst.tracked_at
                FROM puzzle_solving_time pst
                WHERE pst.player_id = :playerId
                    AND pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.unboxed = false
                    AND pst.seconds_to_solve > 0
                    AND pst.predictable = true
                    AND pst.prediction_method = 'personal'
                    AND {$stored} > 0
                    {$exclude}
                    {$beforeCondition}
                ORDER BY COALESCE(pst.finished_at, pst.tracked_at) DESC, pst.tracked_at DESC, pst.id DESC
                LIMIT {$window}
            ) r
        ";
    }

    /**
     * SQL: the stored personal prediction a repeat's residual is measured against - the statistical model's centre
     * (Holt / ratio, the same in every model version): the shown column, unless the shown prediction is the candidate
     * model's (PREDICTION_SHOWN_MODEL = v7: Holt × its gap offset) - then the statistical side row written next to it
     * (NULL without one). Every reader of a repeat's stored centre goes through it (the live readers, RepeatHistory,
     * PredictionReconstructor), so the residuals - and the windows, the fits - do not depend on which model was shown;
     * on rows with a statistical shown prediction it is the column itself.
     */
    public static function storedPersonalSql(string $timeAlias): string
    {
        return sprintf(
            "CASE WHEN %1\$s.prediction_model_version = %2\$d THEN (
                SELECT holt.predicted_seconds FROM solving_time_prediction holt
                WHERE holt.time_id = %1\$s.id AND holt.predictor = 'statistical' AND holt.model_version = %3\$d AND holt.predictable = true AND holt.method = 'personal'
            ) ELSE %1\$s.predicted_seconds END",
            $timeAlias,
            ShownPredictionModel::CANDIDATE_VERSION,
            TimePredictionCalculator::MODEL_VERSION,
        );
    }

    /**
     * @return list<float> oldest first
     */
    public static function fromJson(null|string $json): array
    {
        if ($json === null) {
            return [];
        }

        $pairs = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $residuals = [];

        foreach (is_array($pairs) ? $pairs : [] as $pair) {
            if (is_array($pair) && is_numeric($pair[0] ?? null) && is_numeric($pair[1] ?? null)) {
                $residuals[] = self::residual((int) $pair[0], (int) $pair[1]);
            }
        }

        return $residuals;
    }

    /**
     * The residuals before every solve, in one pass over the player's history: only solves strictly before in the
     * insights order count (solves of the very same moment do not see each other), the last WINDOW of them.
     *
     * @param list<PredictionInputSolve> $solves the player's solves in the insights order
     * @return array<string, list<float>> solve id => its residuals before, oldest first
     */
    public static function beforeEverySolve(array $solves): array
    {
        $before = [];
        $residuals = [];
        $pending = [];
        $previous = null;

        foreach ($solves as $solve) {
            if ($previous !== null && $previous->isBefore($solve)) {
                $residuals = array_slice([...$residuals, ...$pending], -self::WINDOW);
                $pending = [];
            }

            $before[$solve->id] = $residuals;

            if ($solve->qualifying && $solve->seconds > 0 && $solve->storedPersonalPredictionSeconds !== null && $solve->storedPersonalPredictionSeconds > 0) {
                $pending[] = self::residual($solve->seconds, $solve->storedPersonalPredictionSeconds);
            }

            $previous = $solve;
        }

        return $before;
    }

    public static function residual(int $seconds, int $predictedSeconds): float
    {
        return log($seconds / $predictedSeconds);
    }
}
