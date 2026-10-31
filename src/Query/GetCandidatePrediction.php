<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\RepeatResiduals;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\PictureLookalikes;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictionsSideBySide;
use SpeedPuzzling\Web\Value\SolveConditions;
use SpeedPuzzling\Web\Value\SolveMoment;
use SpeedPuzzling\Web\Value\SolvingTimePrediction;
use SpeedPuzzling\Web\Value\TimePredictionSource;

/**
 * The candidate model's (v5, never shown) prediction of a solve being added now - asked by SolvingTimePredictor only
 * after the shown predictions are made, in a savepoint of its own: one statement, never part of a shown reader's (the
 * shown path's statements are exactly what they are without the candidate). Null when the candidate was never fitted.
 *
 * A first try: the statistical inputs once more with the candidate's columns (StatisticalInputsSql::CANDIDATE_COLUMNS),
 * the size curve's (candidatePaceColumns() - by the player's own index), the taste factor's (CANDIDATE_TASTE_COLUMNS: the
 * player's latest taste state, the picture's factors and look-alike numbers - PictureLookalikes::JOINS, v6) and its fit. A repeat: the shown centre with the candidate's window (the player's repeat spread and the days since the
 * last solve).
 *
 * While the candidate is the SHOWN model (PREDICTION_SHOWN_MODEL = v7, ShownPredictionModel) its statement and its two
 * calculations - firstTryOf(), repeatOf() - are also what every shown reader computes with (GetPlayerPrediction,
 * GetPlayerPredictions, GetPersonalEstimates): the shown numbers are exactly the side-by-side row's.
 */
readonly final class GetCandidatePrediction
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private CandidatePredictionCalculator $calculator = new CandidatePredictionCalculator(),
    ) {
    }

    public function forAddedTime(
        string $playerId,
        string $puzzleId,
        PredictionsSideBySide $shown,
        SolveConditions $conditions,
        null|string $excludeTimeId = null,
        null|SolveMoment $before = null,
    ): null|SolvingTimePrediction {
        if ($shown->firstTry === false) {
            $personal = $shown->statistical->result;

            return $personal !== null ? $this->repeat($playerId, $puzzleId, $personal, $excludeTimeId, $before) : null;
        }

        $row = $this->firstTryRow($playerId, $puzzleId);
        $parameters = $row !== null ? self::parametersOf($row) : null;

        if ($row === null || $parameters === null) {
            return null;
        }

        $prediction = $this->firstTryOf($row, $parameters, $conditions);

        return $prediction !== null
            ? SolvingTimePrediction::predicted($prediction, TimePredictionSource::Live, CandidatePredictionCalculator::MODEL_VERSION)
            : SolvingTimePrediction::notPredictable(TimePredictionSource::Live, CandidatePredictionCalculator::MODEL_VERSION);
    }

    /**
     * The candidate's one-puzzle statement: every input of a first try of the player on the puzzle now and the candidate's
     * fit (candidate_parameters); $withShownParameters also the statistical model's (model_parameters) - for a shown
     * reader that falls back to the statistical model while the candidate has no fit. Null = no such puzzle.
     *
     * @return null|array<string, mixed>
     */
    public function firstTryRow(string $playerId, string $puzzleId, bool $withShownParameters = false): null|array
    {
        $joins = StatisticalInputsSql::JOINS . StatisticalInputsSql::lateralPaceJoins() . StatisticalInputsSql::imageEstimateJoins(outOfFoldOnly: false) . PictureLookalikes::JOINS . StatisticalInputsSql::CANDIDATE_JOINS;
        $params = [
            'playerId' => $playerId,
            'puzzleId' => $puzzleId,
            'candidateModelVersion' => CandidatePredictionCalculator::MODEL_VERSION,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];
        $shownParameters = '';

        if ($withShownParameters) {
            $shownParameters = ', (' . PredictionModelParametersProvider::SQL . ') AS model_parameters';
            $params['modelVersion'] = TimePredictionCalculator::MODEL_VERSION;
        }

        /** @var array<string, mixed>|false $row */
        $row = $this->database->executeQuery('
            SELECT ' . StatisticalInputsSql::COLUMNS . ', ' . StatisticalInputsSql::IMAGE_ESTIMATE_COLUMNS . ', ' . StatisticalInputsSql::CANDIDATE_COLUMNS . ', ' . StatisticalInputsSql::CANDIDATE_TASTE_COLUMNS . ',
                ' . StatisticalInputsSql::candidatePaceColumns() . ',
                (' . PredictionModelParametersProvider::CANDIDATE_SQL . ') AS candidate_parameters' . $shownParameters . '
            FROM puzzle p
            ' . $joins . '
            WHERE p.id = :puzzleId
        ', $params)->fetchAssociative();

        return $row !== false ? $row : null;
    }

    /**
     * The candidate's fit on a row (candidate_parameters) - null = never fitted.
     *
     * @param array<string, mixed> $row
     */
    public static function parametersOf(array $row): null|CandidateModelParameters
    {
        return PredictionModelParametersProvider::candidateFromJson(is_string($row['candidate_parameters'] ?? null) ? $row['candidate_parameters'] : null);
    }

    /**
     * The candidate's first try from a row carrying every candidate column (firstTryRow(), or a bulk row with the player's
     * columns - StatisticalInputsSql::withPlayerColumns()): the one calculation of every candidate first try read live,
     * the side-by-side row's and the shown reader's alike.
     *
     * @param array<string, mixed> $row
     */
    public function firstTryOf(array $row, CandidateModelParameters $parameters, SolveConditions $conditions = new SolveConditions()): null|TimePredictionResult
    {
        return $this->calculator->firstTry(StatisticalInputsSql::hydrateCandidateInputs($row, $parameters), $parameters, $conditions);
    }

    /**
     * The candidate's repeat: the statistical model's personal centre with the candidate's gap offset and window - the one
     * calculation of every candidate repeat read live ($repeatSpread = TimePredictionCalculator::playerSpread() of the
     * player's RepeatResiduals before the solve, $gapDays since their last solve of the puzzle).
     */
    public function repeatOf(TimePredictionResult $personal, float $repeatSpread, int $piecesCount, int $gapDays, CandidateModelParameters $parameters): TimePredictionResult
    {
        return $this->calculator->repeatWindow($personal, $repeatSpread, PiecesGroup::fromPiecesCount($piecesCount), $gapDays, $parameters);
    }

    private function repeat(string $playerId, string $puzzleId, TimePredictionResult $personal, null|string $excludeTimeId, null|SolveMoment $before): null|SolvingTimePrediction
    {
        $exclude = $excludeTimeId !== null ? 'AND pst.id != :excludeTimeId' : '';
        $beforeCondition = $before !== null
            ? 'AND (COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at) < (CAST(:solvedAt AS timestamp), CAST(:trackedAt AS timestamp))'
            : '';
        $params = ['playerId' => $playerId, 'puzzleId' => $puzzleId, 'candidateModelVersion' => CandidatePredictionCalculator::MODEL_VERSION];

        if ($excludeTimeId !== null) {
            $params['excludeTimeId'] = $excludeTimeId;
        }

        if ($before !== null) {
            $params['solvedAt'] = $before->solvedAt->format('Y-m-d H:i:s');
            $params['trackedAt'] = $before->trackedAt->format('Y-m-d H:i:s');
        }

        // The last solve: the personal prediction's rule (solo, not suspicious, not unboxed)
        /** @var array{candidate_parameters: null|string, repeats: null|string, last_solved: null|string, pieces_count: null|int|string}|false $row */
        $row = $this->database->fetchAssociative('
            SELECT
                (' . PredictionModelParametersProvider::CANDIDATE_SQL . ') AS candidate_parameters,
                (' . RepeatResiduals::sql($excludeTimeId !== null, $before !== null) . ") AS repeats,
                (
                    SELECT max(COALESCE(pst.finished_at, pst.tracked_at))
                    FROM puzzle_solving_time pst
                    WHERE pst.player_id = :playerId
                        AND pst.puzzle_id = :puzzleId
                        AND pst.puzzling_type = 'solo'
                        AND pst.suspicious = false
                        AND pst.seconds_to_solve IS NOT NULL
                        AND pst.unboxed = false
                        {$exclude}
                        {$beforeCondition}
                ) AS last_solved,
                (SELECT pieces_count FROM puzzle WHERE id = :puzzleId) AS pieces_count
        ", $params);

        $parameters = $row !== false ? PredictionModelParametersProvider::candidateFromJson($row['candidate_parameters']) : null;

        if ($row === false || $parameters === null || $row['last_solved'] === null || $row['pieces_count'] === null) {
            return null;
        }

        $gapDays = TimePredictionCalculator::gapDays($before->solvedAt ?? $this->clock->now(), new DateTimeImmutable($row['last_solved']));
        $window = $this->repeatOf($personal, TimePredictionCalculator::playerSpread(RepeatResiduals::fromJson($row['repeats'])), (int) $row['pieces_count'], $gapDays, $parameters);

        return SolvingTimePrediction::predicted($window, TimePredictionSource::Live, CandidatePredictionCalculator::MODEL_VERSION);
    }
}
