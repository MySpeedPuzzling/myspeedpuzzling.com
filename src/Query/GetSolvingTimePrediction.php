<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Value\SolveMoment;
use SpeedPuzzling\Web\Value\SolvingTimePrediction;
use SpeedPuzzling\Web\Value\TimePredictionMethod;
use SpeedPuzzling\Web\Value\TimePredictionSource;

/**
 * The prediction stored on a solving time - what we predicted for that solve at that moment.
 */
readonly final class GetSolvingTimePrediction
{
    public function __construct(
        private Connection $database,
        private GetPlayerPrediction $getPlayerPrediction,
    ) {
    }

    /**
     * What was predicted for this solve - for the recap page and the API POST response. The stored
     * prediction when there is one; while the time is still pending (a back-dated add waits for its
     * background reconstruction) it is computed now, limited to the solves before this one. That
     * fallback still reads today's baseline and difficulty, so it can differ slightly from what gets
     * stored a moment later.
     */
    public function resultForTime(string $timeId): null|TimePredictionResult
    {
        $stored = $this->byTimeId($timeId);

        if ($stored !== null) {
            return $stored->result;
        }

        /** @var false|array{player_id: string, puzzle_id: string, finished_at: null|string, tracked_at: string} $row */
        $row = $this->database->fetchAssociative(
            'SELECT player_id, puzzle_id, finished_at, tracked_at FROM puzzle_solving_time WHERE id = :timeId',
            ['timeId' => $timeId],
        );

        if ($row === false) {
            return null;
        }

        return $this->getPlayerPrediction->forPuzzle(
            $row['player_id'],
            $row['puzzle_id'],
            excludeTimeId: $timeId,
            before: SolveMoment::of(
                $row['finished_at'] !== null ? new DateTimeImmutable($row['finished_at']) : null,
                new DateTimeImmutable($row['tracked_at']),
            ),
        );
    }

    /**
     * Null when the time was not evaluated (yet): group times, times without seconds, and solo
     * times waiting for the backfill.
     */
    public function byTimeId(string $timeId): null|SolvingTimePrediction
    {
        /** @var false|array{predictable: null|bool, prediction_method: null|string, predicted_seconds: null|int|string, predicted_range_low_seconds: null|int|string, predicted_range_high_seconds: null|int|string, predicted_attempt_number: null|int|string, prediction_last_time_seconds: null|int|string, prediction_source: null|string, prediction_model_version: null|int|string} $row */
        $row = $this->database->fetchAssociative(
            <<<'SQL'
            SELECT predictable, prediction_method, predicted_seconds, predicted_range_low_seconds,
                predicted_range_high_seconds, predicted_attempt_number, prediction_last_time_seconds,
                prediction_source, prediction_model_version
            FROM puzzle_solving_time
            WHERE id = :timeId
            SQL,
            ['timeId' => $timeId],
        );

        if ($row === false || $row['predictable'] === null || $row['prediction_source'] === null || $row['prediction_model_version'] === null) {
            return null;
        }

        $source = TimePredictionSource::from($row['prediction_source']);
        $modelVersion = (int) $row['prediction_model_version'];

        if (
            $row['predictable'] === false
            || $row['predicted_seconds'] === null
            || $row['predicted_range_low_seconds'] === null
            || $row['predicted_range_high_seconds'] === null
        ) {
            return SolvingTimePrediction::notPredictable($source, $modelVersion);
        }

        $isPersonalized = $row['prediction_method'] === TimePredictionMethod::Personal->value;
        $attemptNumber = $row['predicted_attempt_number'] !== null ? (int) $row['predicted_attempt_number'] : null;

        return SolvingTimePrediction::predicted(
            new TimePredictionResult(
                predictedSeconds: (int) $row['predicted_seconds'],
                rangeLowSeconds: (int) $row['predicted_range_low_seconds'],
                rangeHighSeconds: (int) $row['predicted_range_high_seconds'],
                difficultyForPlayer: 0.0,
                isPersonalized: $isPersonalized,
                personalSolveCount: $isPersonalized && $attemptNumber !== null ? $attemptNumber - 1 : null,
                predictedAttemptNumber: $isPersonalized ? $attemptNumber : null,
                lastTimeSeconds: $isPersonalized && $row['prediction_last_time_seconds'] !== null ? (int) $row['prediction_last_time_seconds'] : null,
            ),
            $source,
            $modelVersion,
        );
    }
}
