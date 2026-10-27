<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\AttemptFlags;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidateEngine;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PictureFactorsLookup;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\FirstAttemptSet;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;
use SpeedPuzzling\Web\Value\CandidateEvidence;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\TasteState;
use SpeedPuzzling\Web\Value\ImagePriorInput;
use SpeedPuzzling\Web\Value\MetricConfidence;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictionInputSolve;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Value\PredictionsSideBySide;
use SpeedPuzzling\Web\Value\StatisticalPredictionInputs;
use SpeedPuzzling\Web\Value\SolveConditions;
use SpeedPuzzling\Web\Value\SolvingTimePrediction;
use SpeedPuzzling\Web\Value\TimePredictionSource;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * What today's prediction model would have predicted for past solves of one player, fed only with
 * the data that existed before each solve (docs/features/puzzle-intelligence/prediction-history.md).
 *
 * "Before" is the insights order: (COALESCE(finished_at, tracked_at), tracked_at). Everything the
 * live model reads from its tables is rebuilt here as of the solve - earlier attempts, the gap, the
 * player's improvement ratios, the player's baseline, the puzzle's difficulty - with the same
 * calculator code. The difficulty indices divide each solver's time by their pace-history row valid
 * just before that solve - the lookup the live difficulty uses (PaceTimeline) - with only the rows
 * that existed before the predicted solve; the player's drift and spread are those of their pace-history
 * row before the solve, a repeat's window rests on their stored repeat predictions before it. Documented
 * approximations: the global improvement ratios are a snapshot at the start of the solve's month, and the
 * fitted model (prior groups, tau, thin sizes, windows) is today's fit. Nothing from the solve itself or
 * after it is ever used.
 *
 * Loads its inputs once per player: the player's solo times, the solves of the puzzles that need a
 * statistical prediction and the pace history of their solvers. The rest is PHP.
 *
 * The side-by-side `image` / `blend` predictions of a first try (reconstructSideBySide(), Insights plan §5.4) use the
 * estimate of the puzzle's current picture **only when it is out of fold** (PuzzleDifficultyEstimate::$outOfFold): the
 * model that made it never saw the puzzle's own difficulty label, so no solve of the puzzle - the reconstructed one
 * included - reached it. `outOfFold: false` is pushed only for a puzzle that had no label at training time (the full
 * model never saw it either), so it would leak nothing today - kept out all the same, conservatively: the flag is the
 * only evidence of what the model saw, and the nightly derive re-pushes the puzzle out of fold once it is labelled. Until
 * then the time has no image prediction.
 * There is no estimate history - the reconstruction takes the out-of-fold estimate current when it runs (documented
 * approximation, like the fit). Secret puzzles have none (StatisticalInputsSql::imageEstimateJoins()).
 */
final class PredictionReconstructor implements ResetInterface
{
    private const int PUZZLE_CHUNK_SIZE = 1000;
    // A snapshot ends before the 1st of a month, so only back-dated solves can still change it - a week
    // is fresh enough and spares the worker the month queries of every back-dated add
    private const int SNAPSHOT_CACHE_TTL = 7 * 86400;

    private null|float $scalingExponent = null;

    /** @var array<string, array<int, array<int, array<string, float>>>> month (Y-m) => pieces => from attempt => gap bucket => ratio */
    private array $globalRatioSnapshots = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly PlayerBaselineCalculator $baselineCalculator,
        private readonly PuzzleDifficultyCalculator $difficultyCalculator,
        private readonly PaceTimelineLoader $paceTimelineLoader,
        private readonly PaceHistoryReadiness $paceHistoryReadiness,
        private readonly ImprovementRatioCalculator $improvementRatioCalculator,
        private readonly TimePredictionCalculator $calculator,
        private readonly CacheInterface $globalImprovementRatioSnapshotCache,
        private readonly PredictionModelParametersProvider $parametersProvider,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly CandidatePredictionCalculator $candidateCalculator = new CandidatePredictionCalculator(),
    ) {
    }

    public function reset(): void
    {
        $this->scalingExponent = null;
        $this->globalRatioSnapshots = [];
    }

    /**
     * The statistical model's prediction of each solve (TimePredictionCalculator, prior-only predictions included -
     * SolvingTimePrediction::forDisplay() is what is shown).
     *
     * @param list<string> $timeIds solo times with seconds of this player
     * @return array<string, SolvingTimePrediction> keyed by time id; ids that are not a solo time
     *                                              with seconds of the player are left out
     */
    public function reconstruct(string $playerId, array $timeIds): array
    {
        return array_map(
            static fn (PredictionsSideBySide $predictions): SolvingTimePrediction => $predictions->statistical,
            $this->reconstructSideBySide($playerId, $timeIds),
        );
    }

    /**
     * Every predictor's prediction of each solve: the statistical model's and, for a first try on a puzzle with an
     * out-of-fold estimate of its current picture, the image and blend ones (TimePredictionCalculator::sideBySide()).
     *
     * @param list<string> $timeIds solo times with seconds of this player
     * @param bool $withCandidate also the candidate model's (v4) predictions - after every shown one, a failure there
     *                            costing only them
     * @return array<string, PredictionsSideBySide> keyed by time id; ids that are not a solo time with seconds of the
     *                                              player are left out
     */
    public function reconstructSideBySide(string $playerId, array $timeIds, bool $withCandidate = true): array
    {
        $targetIds = array_flip($timeIds);

        /** @var array<string, list<PredictionInputSolve>> $qualifyingByPuzzle chronological */
        $qualifyingByPuzzle = [];
        $targets = [];
        $playerSolves = $this->fetchPlayerSolves($playerId);

        foreach ($playerSolves as $solve) {
            if ($solve->qualifying) {
                $qualifyingByPuzzle[$solve->puzzleId][] = $solve;
            }

            if (isset($targetIds[$solve->id])) {
                $targets[] = $solve;
            }
        }

        if ($targets === []) {
            return [];
        }

        $priorAttemptsOfTargets = [];
        $statisticalPuzzleIds = [];
        // Before the pace history is built a statistical prediction would find no difficulty indices:
        // those times stay pending (not "not predictable") and the daily backfill comes back to them
        // ... and with it the batch's scaling exponent, so interpolations agree with the live tables
        $storedScalingExponent = $this->paceHistoryReadiness->scalingExponent();
        $paceHistoryBuilt = $storedScalingExponent !== null;
        $this->scalingExponent ??= $storedScalingExponent;

        foreach ($targets as $index => $target) {
            $prior = self::solvesBefore($qualifyingByPuzzle[$target->puzzleId] ?? [], $target);

            if ($prior === [] && $paceHistoryBuilt === false) {
                unset($targets[$index]);

                continue;
            }

            $priorAttemptsOfTargets[$target->id] = $prior;

            if ($prior === []) {
                $statisticalPuzzleIds[$target->puzzleId] = true;
            }
        }

        $parameters = $this->parametersProvider->current();
        $repeatResiduals = RepeatResiduals::beforeEverySolve($playerSolves);
        $playerTransitions = $this->playerTransitions($qualifyingByPuzzle);
        $firstAttemptCandidates = $this->firstAttemptCandidatesByPiecesCount($qualifyingByPuzzle);
        $puzzleFirstAttemptCandidates = $this->puzzleFirstAttemptCandidates(array_keys($statisticalPuzzleIds));
        $solverPaces = $this->solverPaces($puzzleFirstAttemptCandidates, $playerId);
        [$priorContexts, $imageEstimates] = $this->priorContexts(array_keys($statisticalPuzzleIds));

        $predictions = [];
        // The first tries' statistical inputs, kept for the candidate's pass after this loop
        $inputsOfTargets = [];

        foreach ($targets as $target) {
            $prior = $priorAttemptsOfTargets[$target->id];

            if ($prior !== []) {
                $predictions[$target->id] = PredictionsSideBySide::repeat(
                    $this->personalPrediction($prior, $target, $playerTransitions, $repeatResiduals[$target->id] ?? [], $parameters),
                    TimePredictionSource::Reconstructed,
                );

                continue;
            }

            $estimate = $imageEstimates[$target->puzzleId] ?? null;
            $inputs = $this->statisticalInputs($playerId, $target, $firstAttemptCandidates, $puzzleFirstAttemptCandidates[$target->puzzleId] ?? [], $solverPaces, $priorContexts[$target->puzzleId] ?? null, $parameters);

            if ($inputs === null) {
                $predictions[$target->id] = PredictionsSideBySide::firstTry(TimePredictionSource::Reconstructed, null, $estimate);

                continue;
            }

            $inputsOfTargets[$target->id] = $inputs;
            [$statistical, $image, $blend] = $this->calculator->sideBySide($inputs, $estimate, $parameters);
            $predictions[$target->id] = PredictionsSideBySide::firstTry(TimePredictionSource::Reconstructed, $statistical, $estimate, $image, $blend);
        }

        if ($withCandidate === false) {
            return $predictions;
        }

        // The candidate model (v4, never shown) after every shown prediction is made, with statements of its own - a
        // failure there costs only the candidate's rows of this run, never the shown ones
        try {
            return $this->withCandidatePredictions($playerId, $predictions, $targets, $priorAttemptsOfTargets, $inputsOfTargets, $imageEstimates, $puzzleFirstAttemptCandidates, $firstAttemptCandidates, $solverPaces, $qualifyingByPuzzle, $repeatResiduals);
        } catch (Throwable $e) {
            $this->logger->warning('Could not reconstruct the candidate predictions of a player - left for the next backfill', [
                'playerId' => $playerId,
                'exception' => $e,
            ]);

            return $predictions;
        }
    }

    /**
     * The candidate's prediction of every target (once the candidate is fitted): a repeat = the shown centre with the
     * candidate's window; a first try = the shown inputs with the candidate's evidence from the same solvers' first
     * attempts before it (their pace-history row's drift, spread and place in the pair + 5 as the qualifying count),
     * the current candidate brand prior (like the shown prior's sums), the player's first tries before it and their stored
     * taste state before it (v5) with the puzzle's picture factors.
     *
     * @param array<string, PredictionsSideBySide> $predictions
     * @param array<int, PredictionInputSolve> $targets
     * @param array<string, list<PredictionInputSolve>> $priorAttemptsOfTargets
     * @param array<string, StatisticalPredictionInputs> $inputsOfTargets
     * @param array<string, ImagePriorInput> $imageEstimates
     * @param array<string, array<string, list<PredictionInputSolve>>> $puzzleCandidates puzzle => player => candidates
     * @param array<int, array<string, list<PredictionInputSolve>>> $firstAttemptCandidates the player's, pieces count => puzzle => candidates
     * @param array<string, list<PredictionInputSolve>> $qualifyingByPuzzle
     * @param array<string, list<float>> $repeatResiduals
     * @return array<string, PredictionsSideBySide>
     */
    private function withCandidatePredictions(
        string $playerId,
        array $predictions,
        array $targets,
        array $priorAttemptsOfTargets,
        array $inputsOfTargets,
        array $imageEstimates,
        array $puzzleCandidates,
        array $firstAttemptCandidates,
        PaceTimeline $solverPaces,
        array $qualifyingByPuzzle,
        array $repeatResiduals,
    ): array {
        $candidate = $this->parametersProvider->candidate();

        if ($candidate === null) {
            return $predictions;
        }

        $firstTryTargets = array_values(array_filter($targets, static fn (PredictionInputSolve $target): bool => isset($inputsOfTargets[$target->id])));
        $brandPriors = $this->candidateBrandPriors(array_values(array_unique(array_map(static fn (PredictionInputSolve $target): string => $target->puzzleId, $firstTryTargets))));
        $conditions = $this->conditionsOf(array_map(static fn (PredictionInputSolve $target): string => $target->id, $firstTryTargets));
        $flags = $candidate->features()->flagMask() !== 0
            ? $this->attemptFlags(array_values(array_unique(array_map(static fn (PredictionInputSolve $target): string => $target->puzzleId, $firstTryTargets))))
            : [];
        // The taste factor (v5): the player's stored states and the targets' pictures - only with a taste model
        [$tasteStates, $pictures] = $candidate->taste !== null && $firstTryTargets !== []
            ? [$this->tasteStates($playerId, count($candidate->taste->weights)), PictureFactorsLookup::load($this->connection, $this->clock->now(), array_values(array_unique(array_map(static fn (PredictionInputSolve $target): string => $target->puzzleId, $firstTryTargets))))]
            : [[], []];

        foreach ($targets as $target) {
            $shown = $predictions[$target->id] ?? null;

            if ($shown === null) {
                continue;
            }

            $prior = $priorAttemptsOfTargets[$target->id] ?? [];

            if ($prior !== []) {
                $personal = $shown->statistical->result;

                if ($personal !== null) {
                    $predictions[$target->id] = $shown->withCandidate(true, $this->candidateCalculator->repeatWindow(
                        $personal,
                        TimePredictionCalculator::playerSpread($repeatResiduals[$target->id] ?? []),
                        PiecesGroup::fromPiecesCount($target->piecesCount),
                        TimePredictionCalculator::gapDays($target->solvedAt(), $prior[count($prior) - 1]->solvedAt()),
                        $candidate,
                    ), TimePredictionSource::Reconstructed);
                }

                continue;
            }

            $inputs = $inputsOfTargets[$target->id] ?? null;

            if ($inputs === null) {
                $predictions[$target->id] = $shown->withCandidate(true, null, TimePredictionSource::Reconstructed);

                continue;
            }

            $evidence = $this->candidateEvidence($target, $puzzleCandidates[$target->puzzleId] ?? [], $solverPaces, $flags, $candidate);
            $predictions[$target->id] = $shown->withCandidate(true, $this->candidateCalculator->firstTry(
                $this->withCandidatePace(
                    $inputs->withImageEstimate($imageEstimates[$target->puzzleId] ?? null)->withCandidate($evidence, $brandPriors[$target->puzzleId] ?? null, self::firstTriesBefore($qualifyingByPuzzle, $target)),
                    $playerId,
                    $target,
                    $solverPaces,
                    $firstAttemptCandidates[$target->piecesCount] ?? [],
                    $candidate,
                )->withCandidateTaste(self::tasteStateBefore($tasteStates, $target), $pictures[$target->puzzleId] ?? null),
                $candidate,
                $conditions[$target->id] ?? new SolveConditions(),
            ), TimePredictionSource::Reconstructed);
        }

        return $predictions;
    }

    /**
     * The candidate's evidence of the target's puzzle as of the target: every solver's first attempt before it, read as
     * the candidate reads an index (CandidateEngine::term()), the index ceiling of the shown difficulty.
     *
     * @param array<string, list<PredictionInputSolve>> $puzzleCandidates player => candidates
     * @param array<string, int> $flags time id => AttemptFlags
     */
    private function candidateEvidence(PredictionInputSolve $target, array $puzzleCandidates, PaceTimeline $solverPaces, array $flags, CandidateModelParameters $candidate): CandidateEvidence
    {
        $evidence = CandidateEvidence::none();

        foreach ($puzzleCandidates as $solverId => $candidates) {
            $firstAttempt = self::firstBefore($candidates, $target);

            if ($firstAttempt === null) {
                continue;
            }

            $pace = $solverPaces->paceAt((string) $solverId, $firstAttempt->piecesCount, $firstAttempt->solved, $firstAttempt->tracked, knownBefore: [$target->solved, $target->tracked]);
            $index = $pace !== null && $pace > 0 ? $firstAttempt->seconds / $pace : 0.0;

            if ($index <= 0.0 || $index > FirstAttemptSet::INDEX_CEILING) {
                continue;
            }

            $row = $solverPaces->directRowBefore((string) $solverId, $firstAttempt->piecesCount, $firstAttempt->solved, $firstAttempt->tracked, knownBefore: [$target->solved, $target->tracked]);
            $term = CandidateEngine::term(
                log($index),
                $row !== null ? ($row['drift'] ?? 0.0) : 0.0,
                $row !== null ? ($row['spread'] ?? PredictionModelParameters::GLOBAL_SPREAD) : PredictionModelParameters::GLOBAL_SPREAD,
                // The pair's rows start at its 5th qualifying first attempt
                $row !== null ? $row['position'] + PlayerBaselineCalculator::MINIMUM_SOLVE_COUNT : 0,
                ($flags[$firstAttempt->id] ?? 0) | ($firstAttempt->firstAttempt ? 0 : AttemptFlags::UNFLAGGED),
                $candidate,
            );
            $evidence = $evidence->with($term[0], $term[1], $term[2]);
        }

        return $evidence;
    }

    /**
     * The size curve's inputs as of the target (SizeCurve::pace()): the player's direct pace row before it at every piece
     * count (drift and spread of the row, its place in the pair + 5 as the qualifying count) and, without a direct one at
     * the target's size, their 1-4 first tries there with each puzzle's candidate difficulty now (the live path's rule -
     * a documented approximation like the priors' sums).
     *
     * @param array<string, list<PredictionInputSolve>> $candidatesAtSize the player's candidates at the target's piece count, by puzzle
     */
    private function withCandidatePace(StatisticalPredictionInputs $inputs, string $playerId, PredictionInputSolve $target, PaceTimeline $solverPaces, array $candidatesAtSize, CandidateModelParameters $candidate): StatisticalPredictionInputs
    {
        if ($candidate->sizeCurve === null || $candidate->features()->on('size_curve') === false) {
            return $inputs;
        }

        $rows = [];

        foreach ($solverPaces->piecesCountsOf($playerId) as $piecesCount) {
            $row = $solverPaces->directRowBefore($playerId, $piecesCount, $target->solved, $target->tracked);

            if ($row !== null && $row['baseline'] > 0) {
                $rows[] = [$piecesCount, $row['baseline'], $row['drift'] ?? 0.0, $row['spread'] ?? PredictionModelParameters::GLOBAL_SPREAD, $row['position'] + PlayerBaselineCalculator::MINIMUM_SOLVE_COUNT];
            }
        }

        $few = null;

        if ($solverPaces->directRowBefore($playerId, $target->piecesCount, $target->solved, $target->tracked) === null) {
            $firstTries = [];

            foreach ($candidatesAtSize as $candidates) {
                $firstTry = self::firstBefore($candidates, $target);

                if ($firstTry !== null && $firstTry->seconds > 0) {
                    $firstTries[] = $firstTry;
                }
            }

            if ($firstTries !== [] && count($firstTries) < PlayerBaselineCalculator::MINIMUM_SOLVE_COUNT) {
                $difficulties = $this->currentCandidateDifficulties(array_map(static fn (PredictionInputSolve $solve): string => $solve->puzzleId, $firstTries), $candidate);
                $sum = 0.0;

                foreach ($firstTries as $firstTry) {
                    $sum += log($firstTry->seconds) - ($difficulties[$firstTry->puzzleId] ?? 0.0);
                }

                $few = [count($firstTries), $sum];
            }
        }

        return $inputs->withCandidatePace($rows, $few);
    }

    /**
     * The candidate log difficulty of puzzles now (their stored candidate evidence, brand prior and out-of-fold estimate).
     *
     * @param list<string> $puzzleIds
     * @return array<string, float>
     */
    private function currentCandidateDifficulties(array $puzzleIds, CandidateModelParameters $candidate): array
    {
        /** @var list<array{id: string, index_count: null|int|string, weight_sum: null|float|string, weighted_log_sum: null|float|string, brand_prior_log: null|float|string, image_log: null|float|string}> $rows */
        $rows = $this->connection->fetchAllAssociative('
            SELECT p.id, ce.index_count, ce.weight_sum, ce.weighted_log_sum,
                CASE WHEN ' . StatisticalInputsSql::NOT_SECRET . ' THEN cpp.brand_prior_log END AS brand_prior_log,
                img.log_difficulty AS image_log
            FROM puzzle p
            LEFT JOIN candidate_puzzle_evidence ce ON ce.puzzle_id = p.id
            LEFT JOIN candidate_puzzle_prior cpp ON cpp.puzzle_id = p.id
            ' . StatisticalInputsSql::imageEstimateJoins(outOfFoldOnly: true) . '
            WHERE p.id IN (:puzzleIds)
        ', [
            'puzzleIds' => $puzzleIds,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['puzzleIds' => ArrayParameterType::STRING]);

        $difficulties = [];

        foreach ($rows as $row) {
            $evidence = $row['index_count'] !== null
                ? new CandidateEvidence((int) $row['index_count'], (float) $row['weight_sum'], (float) $row['weighted_log_sum'])
                : CandidateEvidence::none();
            $difficulties[$row['id']] = CandidatePredictionCalculator::puzzleLogDifficulty(
                $evidence,
                $row['image_log'] !== null ? (float) $row['image_log'] : null,
                $row['brand_prior_log'] !== null ? (float) $row['brand_prior_log'] : null,
                $candidate,
            );
        }

        return $difficulties;
    }

    /**
     * The player's stored taste states (candidate_player_taste) in their order: [valid from (solved, tracked), state].
     *
     * @return list<array{0: float, 1: float, 2: TasteState}>
     */
    private function tasteStates(string $playerId, int $rank): array
    {
        $states = [];

        /** @var list<array{solved: float|string, tracked: float|string, state: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('
            SELECT EXTRACT(EPOCH FROM valid_from) AS solved, EXTRACT(EPOCH FROM valid_from_tracked_at) AS tracked, CAST(state AS text) AS state
            FROM candidate_player_taste
            WHERE player_id = :playerId
            ORDER BY valid_from, valid_from_tracked_at
        ', ['playerId' => $playerId]);

        foreach ($rows as $row) {
            $state = TasteState::fromJson($row['state'], $rank);

            if ($state !== null) {
                $states[] = [(float) $row['solved'], (float) $row['tracked'], $state];
            }
        }

        return $states;
    }

    /**
     * The latest state valid strictly before the target (its first tries before it, as of the candidate's last fit).
     *
     * @param list<array{0: float, 1: float, 2: TasteState}> $states in their order
     */
    private static function tasteStateBefore(array $states, PredictionInputSolve $target): null|TasteState
    {
        $before = null;

        foreach ($states as [$solved, $tracked, $state]) {
            if (($solved <=> $target->solved ?: $tracked <=> $target->tracked) >= 0) {
                break;
            }

            $before = $state;
        }

        return $before;
    }

    /**
     * The candidate's brand prior of puzzles (candidate_puzzle_prior) - none for a secret puzzle.
     *
     * @param list<string> $puzzleIds
     * @return array<string, float>
     */
    private function candidateBrandPriors(array $puzzleIds): array
    {
        $priors = [];

        foreach (array_chunk($puzzleIds, self::PUZZLE_CHUNK_SIZE) as $chunk) {
            /** @var array<string, float|string> $rows */
            $rows = $this->connection->fetchAllKeyValue('
                SELECT p.id, cpp.brand_prior_log
                FROM puzzle p
                JOIN candidate_puzzle_prior cpp ON cpp.puzzle_id = p.id
                WHERE p.id IN (:puzzleIds) AND ' . StatisticalInputsSql::NOT_SECRET . '
            ', ['puzzleIds' => $chunk, 'now' => $this->clock->now()->format('Y-m-d H:i:s')], ['puzzleIds' => ArrayParameterType::STRING]);

            foreach ($rows as $puzzleId => $prior) {
                $priors[$puzzleId] = (float) $prior;
            }
        }

        return $priors;
    }

    /**
     * The conditions each time was solved in: unboxed, at an event (linked to an edition or one-time event, or a series
     * pick still at series level - competition_series_id).
     *
     * @param list<string> $timeIds
     * @return array<string, SolveConditions>
     */
    private function conditionsOf(array $timeIds): array
    {
        $conditions = [];

        foreach (array_chunk($timeIds, self::PUZZLE_CHUNK_SIZE) as $chunk) {
            /** @var list<array{id: string, unboxed: bool, competition: bool}> $rows */
            $rows = $this->connection->fetchAllAssociative(
                'SELECT pst.id, pst.unboxed, (pst.competition_id IS NOT NULL OR pst.competition_series_id IS NOT NULL) AS competition FROM puzzle_solving_time pst WHERE pst.id IN (:timeIds)',
                ['timeIds' => $chunk],
                ['timeIds' => ArrayParameterType::STRING],
            );

            foreach ($rows as $row) {
                $conditions[$row['id']] = new SolveConditions(unboxed: (bool) $row['unboxed'], competition: (bool) $row['competition']);
            }
        }

        return $conditions;
    }

    /**
     * AttemptFlags of every solo time on the puzzles - only when the candidate's fit weighs indices by them.
     *
     * @param list<string> $puzzleIds
     * @return array<string, int> time id => flags
     */
    private function attemptFlags(array $puzzleIds): array
    {
        $flags = [];

        foreach (array_chunk($puzzleIds, self::PUZZLE_CHUNK_SIZE) as $chunk) {
            /** @var list<array{id: string, first_attempt: bool, commented: bool, competition: bool, after_group: bool}> $rows */
            $rows = $this->connection->fetchAllAssociative('
                SELECT pst.id, ' . AttemptFlags::FLAG_COLUMNS . '
                FROM puzzle_solving_time pst
                ' . AttemptFlags::flagJoin('g.puzzle_id IN (:puzzleIds)') . "
                WHERE pst.puzzle_id IN (:puzzleIds) AND pst.puzzling_type = 'solo' AND pst.suspicious = false
            ", ['puzzleIds' => $chunk], ['puzzleIds' => ArrayParameterType::STRING]);

            foreach ($rows as $row) {
                $flags[$row['id']] = AttemptFlags::mask((bool) $row['first_attempt'], (bool) $row['after_group'], (bool) $row['commented'], (bool) $row['competition']);
            }
        }

        return $flags;
    }

    /**
     * The player's qualifying first attempts (one per puzzle, every size) before the target - the candidate's
     * experience.
     *
     * @param array<string, list<PredictionInputSolve>> $qualifyingByPuzzle
     */
    private static function firstTriesBefore(array $qualifyingByPuzzle, PredictionInputSolve $target): int
    {
        $count = 0;

        foreach ($qualifyingByPuzzle as $solves) {
            foreach ($solves as $solve) {
                if ($solve->isBefore($target)) {
                    $count++;

                    break;
                }
            }
        }

        return $count;
    }

    /**
     * @param non-empty-list<PredictionInputSolve> $prior
     * @param list<array{transition: array{from_attempt: int, ratio: float, gap_days: float}, at: PredictionInputSolve}> $playerTransitions
     * @param list<float> $repeatResiduals the residuals of the player's stored repeat predictions before the target
     */
    private function personalPrediction(array $prior, PredictionInputSolve $target, array $playerTransitions, array $repeatResiduals, PredictionModelParameters $parameters): TimePredictionResult
    {
        /** @var non-empty-list<int> $times */
        $times = array_map(static fn (PredictionInputSolve $solve): int => $solve->seconds, $prior);
        $last = $prior[count($prior) - 1];

        $transition = TimePredictionCalculator::transitionFor(count($prior));
        $gapBucket = TimePredictionCalculator::classifyGap(
            TimePredictionCalculator::gapDays($target->solvedAt(), $last->solvedAt()),
        );

        // A transition exists from its later attempt on
        $transitionsBefore = [];

        foreach ($playerTransitions as $playerTransition) {
            if ($playerTransition['at']->isBefore($target)) {
                $transitionsBefore[] = $playerTransition['transition'];
            }
        }

        $playerRatio = null;

        foreach ($this->improvementRatioCalculator->playerRatiosFromTransitions($transitionsBefore) as $ratio) {
            if ($ratio['from_attempt'] === $transition) {
                $playerRatio = $ratio['median_ratio'];
            }
        }

        $globalRatios = $this->globalRatiosBefore($target->solvedAt())[$target->piecesCount][$transition] ?? [];

        return $this->calculator->personal(
            $times,
            TimePredictionCalculator::resolveRatio(
                $playerRatio,
                $globalRatios[$gapBucket] ?? null,
                // The "all" bucket is only needed to gap-correct a player ratio (as in GetPlayerPrediction)
                $playerRatio !== null ? ($globalRatios['all'] ?? null) : null,
            ),
            TimePredictionCalculator::playerSpread($repeatResiduals),
            $target->piecesCount,
            $parameters,
        );
    }

    /**
     * The statistical inputs of a first try as of its solve - null when the player had no pace for the piece count
     * then (own or the 500-scaled one), so nothing can be predicted.
     *
     * @param array<int, array<string, list<PredictionInputSolve>>> $firstAttemptCandidates the player's, pieces count => puzzle => candidates
     * @param array<string, list<PredictionInputSolve>> $puzzleCandidates every solver of the target's puzzle, player => candidates
     */
    private function statisticalInputs(
        string $playerId,
        PredictionInputSolve $target,
        array $firstAttemptCandidates,
        array $puzzleCandidates,
        PaceTimeline $solverPaces,
        null|PriorContext $priorContext,
        PredictionModelParameters $parameters,
    ): null|StatisticalPredictionInputs {
        [$ownBaseline, $ownDirect, $ownCount] = $this->baselineBefore($target, $firstAttemptCandidates);
        $pace500 = $target->piecesCount !== 500 ? $this->directBaselineBefore($target, $firstAttemptCandidates[500] ?? []) : null;
        $prior = $priorContext ?? new PriorContext($target->puzzleId, $target->piecesCount);

        // No pace for the piece count (own or the 500-scaled one): no difficulty to compute either
        $paceOnly = new StatisticalPredictionInputs($target->piecesCount, $ownBaseline, $ownDirect, $ownCount, null, null, $pace500, null, null, 0, null, null, $prior);

        if (TimePredictionCalculator::baselineFor($paceOnly, $parameters) === null) {
            return null;
        }

        $firstAttempts = [];

        foreach ($puzzleCandidates as $solverId => $candidates) {
            $firstAttempt = self::firstBefore($candidates, $target);

            if ($firstAttempt === null) {
                continue;
            }

            // Only pace rows that existed before the predicted solve - also for a solver's first pace
            $pace = $solverPaces->paceAt(
                (string) $solverId,
                $firstAttempt->piecesCount,
                $firstAttempt->solved,
                $firstAttempt->tracked,
                knownBefore: [$target->solved, $target->tracked],
            );

            if ($pace !== null) {
                $firstAttempts[] = ['seconds_to_solve' => $firstAttempt->seconds, 'baseline_seconds' => $pace];
            }
        }

        $difficulty = $this->difficultyCalculator->calculateFromSolves($firstAttempts);
        $ownRow = $solverPaces->directRowBefore($playerId, $target->piecesCount, $target->solved, $target->tracked);
        $fiveRow = $pace500 !== null ? $solverPaces->directRowBefore($playerId, 500, $target->solved, $target->tracked) : null;

        return new StatisticalPredictionInputs(
            piecesCount: $target->piecesCount,
            ownBaselineSeconds: $ownBaseline,
            ownBaselineDirect: $ownDirect,
            ownQualifyingCount: $ownCount,
            ownDrift: $ownDirect ? $ownRow['drift'] ?? null : null,
            ownSpread: $ownDirect ? $ownRow['spread'] ?? null : null,
            pace500Seconds: $pace500,
            drift500: $pace500 !== null ? $fiveRow['drift'] ?? null : null,
            spread500: $pace500 !== null ? $fiveRow['spread'] ?? null : null,
            kIndices: $difficulty['sample_size'],
            sumLogIndices: $difficulty['sum_log_indices'],
            difficultyScore: $difficulty['confidence'] !== MetricConfidence::Insufficient ? $difficulty['difficulty_score'] : null,
            prior: $prior,
        );
    }

    /**
     * The player's baseline for the target's piece count as the recalculation would have computed it
     * right before the solve: direct from ≥ 5 first attempts (decay measured to the solve), otherwise
     * interpolated/extrapolated from the other piece counts' direct baselines - only when the player
     * had solved that piece count before at all (PlayerBaselineCalculator::findBaselineGaps()).
     *
     * @param array<int, array<string, list<PredictionInputSolve>>> $firstAttemptCandidates
     * @return array{0: null|int, 1: bool, 2: int} baseline, direct, qualifying first attempts behind a direct one
     */
    private function baselineBefore(PredictionInputSolve $target, array $firstAttemptCandidates): array
    {
        $firstAttempts = self::firstAttemptsBefore($firstAttemptCandidates[$target->piecesCount] ?? [], $target);

        if (count($firstAttempts) >= PlayerBaselineCalculator::MINIMUM_SOLVE_COUNT) {
            return [$this->baselineCalculator->weightedBaselineSeconds($firstAttempts, $target->solvedAt()), true, count($firstAttempts)];
        }

        if ($firstAttempts === []) {
            return [null, false, 0];
        }

        $directBaselines = [];

        foreach ($firstAttemptCandidates as $piecesCount => $candidatesByPuzzle) {
            if ($piecesCount === $target->piecesCount) {
                continue;
            }

            $otherFirstAttempts = self::firstAttemptsBefore($candidatesByPuzzle, $target);

            if (count($otherFirstAttempts) >= PlayerBaselineCalculator::MINIMUM_SOLVE_COUNT) {
                $directBaselines[$piecesCount] = $this->baselineCalculator->weightedBaselineSeconds($otherFirstAttempts, $target->solvedAt());
            }
        }

        ksort($directBaselines);

        $this->scalingExponent ??= $this->baselineCalculator->computeScalingExponent();

        return [$this->baselineCalculator->gapBaseline($target->piecesCount, $directBaselines, $this->scalingExponent)['baseline_seconds'] ?? null, false, 0];
    }

    /**
     * The player's direct baseline at a piece count right before the solve (the 500-piece pace of the thin-size rule).
     *
     * @param array<string, list<PredictionInputSolve>> $candidatesByPuzzle
     */
    private function directBaselineBefore(PredictionInputSolve $target, array $candidatesByPuzzle): null|int
    {
        $firstAttempts = self::firstAttemptsBefore($candidatesByPuzzle, $target);

        return count($firstAttempts) >= PlayerBaselineCalculator::MINIMUM_SOLVE_COUNT
            ? $this->baselineCalculator->weightedBaselineSeconds($firstAttempts, $target->solvedAt())
            : null;
    }

    /**
     * BrandPiecesPrior's inputs of the target puzzles as stored by the last fit (the live statements' join), and their
     * out-of-fold image estimates (the same statement - the image / blend reconstructions cost no query of their own).
     *
     * @param list<string> $puzzleIds
     * @return array{0: array<string, PriorContext>, 1: array<string, ImagePriorInput>}
     */
    private function priorContexts(array $puzzleIds): array
    {
        $contexts = [];
        $estimates = [];

        foreach (array_chunk($puzzleIds, self::PUZZLE_CHUNK_SIZE) as $chunk) {
            /** @var list<array{id: string, pieces_count: int|string, manufacturer_id: null|string, prior_fit_sum_log: null|float|string, prior_fit_count: null|int|string, group_sum_log: null|float|string, group_index_count: null|int|string}> $rows */
            $rows = $this->connection->fetchAllAssociative('
                SELECT p.id, p.pieces_count, p.manufacturer_id, dpp.sum_log_indices AS prior_fit_sum_log, dpp.index_count AS prior_fit_count,
                    g.sum_log_indices AS group_sum_log, g.index_count AS group_index_count,
                    ' . StatisticalInputsSql::IMAGE_ESTIMATE_COLUMNS . '
                FROM puzzle p
                LEFT JOIN difficulty_prior_puzzle dpp ON dpp.puzzle_id = p.id
                LEFT JOIN difficulty_prior_group g ON g.manufacturer_id = p.manufacturer_id AND g.pieces_count = p.pieces_count AND ' . StatisticalInputsSql::NOT_SECRET . '
                ' . StatisticalInputsSql::imageEstimateJoins(outOfFoldOnly: true) . '
                WHERE p.id IN (:puzzleIds)
            ', [
                'puzzleIds' => $chunk,
                // Secret at the real present, never as of the solve: a puzzle secret today must not leak into a
                // reconstruction made today, whenever it was solved
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ], ['puzzleIds' => ArrayParameterType::STRING]);

            foreach ($rows as $row) {
                $estimate = ImagePriorInput::fromRow($row);

                if ($estimate !== null) {
                    $estimates[$row['id']] = $estimate;
                }

                $contexts[$row['id']] = new PriorContext(
                    puzzleId: $row['id'],
                    piecesCount: (int) $row['pieces_count'],
                    manufacturerId: $row['manufacturer_id'],
                    brandPiecesGroupSumLog: $row['group_sum_log'] !== null ? (float) $row['group_sum_log'] : 0.0,
                    brandPiecesGroupCount: $row['group_index_count'] !== null ? (int) $row['group_index_count'] : 0,
                    brandPiecesOwnSumLog: $row['prior_fit_sum_log'] !== null ? (float) $row['prior_fit_sum_log'] : 0.0,
                    brandPiecesOwnCount: $row['prior_fit_count'] !== null ? (int) $row['prior_fit_count'] : 0,
                );
            }
        }

        return [$contexts, $estimates];
    }

    /**
     * @param array<string, list<PredictionInputSolve>> $candidatesByPuzzle
     * @return list<array{seconds: int, solved_at: DateTimeImmutable}>
     */
    private static function firstAttemptsBefore(array $candidatesByPuzzle, PredictionInputSolve $target): array
    {
        $firstAttempts = [];

        foreach ($candidatesByPuzzle as $candidates) {
            $firstAttempt = self::firstBefore($candidates, $target);

            if ($firstAttempt !== null) {
                $firstAttempts[] = ['seconds' => $firstAttempt->seconds, 'solved_at' => $firstAttempt->solvedAt()];
            }
        }

        return $firstAttempts;
    }

    /**
     * The first attempt as of the target: the candidates come in the insights preference order
     * (flagged first_attempt, then the oldest), so it is the first one solved before the target -
     * a later solve flagged first_attempt must not pull the future in.
     *
     * @param list<PredictionInputSolve> $candidates
     */
    private static function firstBefore(array $candidates, PredictionInputSolve $target): null|PredictionInputSolve
    {
        foreach ($candidates as $candidate) {
            if ($candidate->isBefore($target)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<PredictionInputSolve> $solves
     * @return list<PredictionInputSolve>
     */
    private static function solvesBefore(array $solves, PredictionInputSolve $target): array
    {
        return array_values(array_filter(
            $solves,
            static fn (PredictionInputSolve $solve): bool => $solve->isBefore($target),
        ));
    }

    /**
     * Consecutive attempts of every puzzle, each with the later attempt it exists from.
     *
     * @param array<string, list<PredictionInputSolve>> $qualifyingByPuzzle
     * @return list<array{transition: array{from_attempt: int, ratio: float, gap_days: float}, at: PredictionInputSolve}>
     */
    private function playerTransitions(array $qualifyingByPuzzle): array
    {
        $transitions = [];

        foreach ($qualifyingByPuzzle as $solves) {
            for ($i = 1, $count = count($solves); $i < $count; $i++) {
                $transition = ImprovementRatioCalculator::transition(
                    $solves[$i - 1]->seconds,
                    $solves[$i]->seconds,
                    $i,
                    ($solves[$i]->solved - $solves[$i - 1]->solved) / 86400.0,
                );

                if ($transition !== null) {
                    $transitions[] = ['transition' => $transition, 'at' => $solves[$i]];
                }
            }
        }

        return $transitions;
    }

    /**
     * @param array<string, list<PredictionInputSolve>> $qualifyingByPuzzle
     * @return array<int, array<string, list<PredictionInputSolve>>> pieces count => puzzle => candidates in first-attempt preference order
     */
    private function firstAttemptCandidatesByPiecesCount(array $qualifyingByPuzzle): array
    {
        $candidates = [];

        foreach ($qualifyingByPuzzle as $puzzleId => $solves) {
            if ($solves !== []) {
                $candidates[$solves[0]->piecesCount][$puzzleId] = self::inFirstAttemptOrder($solves);
            }
        }

        return $candidates;
    }

    /**
     * Qualifying solves of every player on the given puzzles.
     *
     * @param list<string> $puzzleIds
     * @return array<string, array<string, list<PredictionInputSolve>>> puzzle => player => candidates in first-attempt preference order
     */
    private function puzzleFirstAttemptCandidates(array $puzzleIds): array
    {
        $byPuzzleAndPlayer = [];

        foreach (array_chunk($puzzleIds, self::PUZZLE_CHUNK_SIZE) as $chunk) {
            /** @var list<array{id: string, puzzle_id: string, player_id: string, pieces_count: int|string, seconds_to_solve: int|string, first_attempt: bool, solved_ts: float|string, tracked_ts: float|string}> $rows */
            $rows = $this->connection->fetchAllAssociative(
                <<<'SQL'
                SELECT
                    pst.id,
                    pst.puzzle_id,
                    pst.player_id,
                    p.pieces_count,
                    pst.seconds_to_solve,
                    pst.first_attempt,
                    EXTRACT(EPOCH FROM COALESCE(pst.finished_at, pst.tracked_at)) AS solved_ts,
                    EXTRACT(EPOCH FROM pst.tracked_at) AS tracked_ts
                FROM puzzle_solving_time pst
                JOIN puzzle p ON p.id = pst.puzzle_id
                WHERE pst.puzzle_id IN (:puzzleIds)
                    AND pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve IS NOT NULL
                    AND pst.unboxed = false
                SQL,
                ['puzzleIds' => $chunk],
                ['puzzleIds' => ArrayParameterType::STRING],
            );

            foreach ($rows as $row) {
                $byPuzzleAndPlayer[$row['puzzle_id']][$row['player_id']][] = new PredictionInputSolve(
                    id: $row['id'],
                    puzzleId: $row['puzzle_id'],
                    piecesCount: (int) $row['pieces_count'],
                    seconds: (int) $row['seconds_to_solve'],
                    firstAttempt: (bool) $row['first_attempt'],
                    qualifying: true,
                    solved: (float) $row['solved_ts'],
                    tracked: (float) $row['tracked_ts'],
                );
            }
        }

        $candidates = [];

        foreach ($byPuzzleAndPlayer as $puzzleId => $byPlayer) {
            foreach ($byPlayer as $playerId => $solves) {
                $candidates[$puzzleId][$playerId] = self::inFirstAttemptOrder($solves);
            }
        }

        return $candidates;
    }

    /**
     * The pace history of every solver of the statistical targets' puzzles, and the player's own (drift, spread).
     *
     * @param array<string, array<string, list<PredictionInputSolve>>> $puzzleFirstAttemptCandidates puzzle => player => candidates
     */
    private function solverPaces(array $puzzleFirstAttemptCandidates, string $playerId): PaceTimeline
    {
        $solverIds = $puzzleFirstAttemptCandidates !== [] ? [$playerId => true] : [];

        foreach ($puzzleFirstAttemptCandidates as $byPlayer) {
            foreach (array_keys($byPlayer) as $solverId) {
                $solverIds[(string) $solverId] = true;
            }
        }

        $this->scalingExponent ??= $this->baselineCalculator->computeScalingExponent();

        return $this->paceTimelineLoader->forPlayers(array_keys($solverIds), $this->scalingExponent);
    }

    /**
     * The insights "first attempt" preference: solves flagged first_attempt first, then the oldest.
     *
     * @param list<PredictionInputSolve> $solves
     * @return list<PredictionInputSolve>
     */
    private static function inFirstAttemptOrder(array $solves): array
    {
        usort(
            $solves,
            static fn (PredictionInputSolve $a, PredictionInputSolve $b): int => [$b->firstAttempt, $a->solved, $a->tracked, $a->id] <=> [$a->firstAttempt, $b->solved, $b->tracked, $b->id],
        );

        return $solves;
    }

    /**
     * Every solo time with seconds of the player, chronological in the insights order.
     *
     * @return list<PredictionInputSolve>
     */
    private function fetchPlayerSolves(string $playerId): array
    {
        /** @var list<array{id: string, puzzle_id: string, pieces_count: int|string, seconds_to_solve: int|string, first_attempt: bool, suspicious: bool, unboxed: bool, stored_personal: null|int|string, solved_ts: float|string, tracked_ts: float|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                pst.id,
                pst.puzzle_id,
                p.pieces_count,
                pst.seconds_to_solve,
                pst.first_attempt,
                pst.suspicious,
                pst.unboxed,
                CASE WHEN pst.predictable = true AND pst.prediction_method = 'personal' THEN pst.predicted_seconds END AS stored_personal,
                EXTRACT(EPOCH FROM COALESCE(pst.finished_at, pst.tracked_at)) AS solved_ts,
                EXTRACT(EPOCH FROM pst.tracked_at) AS tracked_ts
            FROM puzzle_solving_time pst
            JOIN puzzle p ON p.id = pst.puzzle_id
            WHERE pst.player_id = :playerId
                AND pst.puzzling_type = 'solo'
                AND pst.seconds_to_solve IS NOT NULL
            ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at, pst.id
            SQL,
            ['playerId' => $playerId],
        );

        return array_map(static fn (array $row): PredictionInputSolve => new PredictionInputSolve(
            id: $row['id'],
            puzzleId: $row['puzzle_id'],
            piecesCount: (int) $row['pieces_count'],
            seconds: (int) $row['seconds_to_solve'],
            firstAttempt: (bool) $row['first_attempt'],
            qualifying: !$row['suspicious'] && !$row['unboxed'],
            solved: (float) $row['solved_ts'],
            tracked: (float) $row['tracked_ts'],
            storedPersonalPredictionSeconds: $row['stored_personal'] !== null ? (int) $row['stored_personal'] : null,
        ), $rows);
    }

    /**
     * Snapshot of the global ratios from the transitions before the 1st of the solve's month.
     * Cached (not only memoised): the messenger worker resets this service after every message.
     *
     * @return array<int, array<int, array<string, float>>>
     */
    private function globalRatiosBefore(DateTimeImmutable $solvedAt): array
    {
        $monthStart = $solvedAt->modify('first day of this month midnight');
        $month = $monthStart->format('Y-m');

        return $this->globalRatioSnapshots[$month] ??= $this->globalImprovementRatioSnapshotCache->get(
            'global_ratios_before_' . $month,
            function (ItemInterface $item) use ($monthStart): array {
                $item->expiresAfter(self::SNAPSHOT_CACHE_TTL);

                return $this->improvementRatioCalculator->globalRatiosBefore($monthStart);
            },
        );
    }
}
