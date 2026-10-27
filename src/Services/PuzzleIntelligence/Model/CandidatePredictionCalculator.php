<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\BrandPiecesPrior;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\ImageEstimatePrior;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\CandidateEvidence;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\DifficultySource;
use SpeedPuzzling\Web\Value\PaceSource;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Value\SizeCurve;
use SpeedPuzzling\Web\Value\SolveConditions;
use SpeedPuzzling\Web\Value\StatisticalPredictionInputs;
use SpeedPuzzling\Web\Value\WindowBucket;

/**
 * The candidate prediction model (v5 = v4 + the taste factor, README "Step 8", "The candidate model (v4)", "The taste
 * factor (v5)") - computed side by side with the
 * shown model (TimePredictionCalculator, v3), stored as the `candidate` predictor's rows of solving_time_prediction,
 * never shown. Pure math, no database: the live statement, the reconstructor and the evaluation feed it the same way.
 *
 * First try: pace × e^drift (the shown model's pace, TimePredictionCalculator::baselineFor()) × e^(posterior log
 * difficulty) × e^(centre offset) × e^(the player's taste term) [× e^(unboxed offset)]. The posterior is one formula for every k:
 * (Σ w l + m ÷ v) ÷ (Σ w + 1 ÷ v) over the puzzle's drift-adjusted, precision-weighted indices (CandidateEvidence) and
 * the first prior there is - the image estimate, else the puzzle-weighted brand × pieces prior, else the average
 * puzzle - each prior type with its own fitted variance v (v6: an image estimate's from its own interval). The window:
 * sigma² from the player's spread, the posterior variance, the size group, the pace source and the puzzle's dispersion
 * (v6: at k = 0 how alike the picture's pieces look; an unboxed solve's own variance), × a context scale; quantiles per
 * key with the prior type.
 *
 * Repeat: the shown model's centre (TimePredictionCalculator::personal()) × e^(gap offset), the window with the gap
 * since the last solve.
 */
final readonly class CandidatePredictionCalculator
{
    /** The model version of the candidate's rows (solving_time_prediction, prediction_model_fit) - 5 = v4 + the taste factor, 6 = v5 + the image interval, the k = 0 picture term and the unboxed window */
    public const int MODEL_VERSION = 6;

    public function __construct(
        private TimePredictionCalculator $shown = new TimePredictionCalculator(),
    ) {
    }

    public function firstTry(
        StatisticalPredictionInputs $inputs,
        CandidateModelParameters $parameters,
        SolveConditions $conditions = new SolveConditions(),
    ): null|TimePredictionResult {
        $features = $parameters->features();
        $pace = self::pace($inputs, $parameters);

        if ($pace === null) {
            return null;
        }

        [$logPace, $paceVariance, $spread, $paceSource] = $pace;
        $evidence = $inputs->candidateEvidence ?? CandidateEvidence::none();
        $k = $evidence->k;
        [$priorType, $priorLog] = $this->prior($inputs, $features->on('image_prior'), $features->on('puzzle_weighted_brand'));
        $priorVariance = self::priorVarianceOf($parameters, $priorType, $priorType === ImageEstimatePrior::NAME ? $inputs->prior->imageEstimate?->variance() : null);

        if ($features->on('posterior_all_k') === false && $k >= TimePredictionCalculator::RATED_INDICES && $inputs->difficultyScore !== null && $inputs->difficultyScore > 0.0 && $evidence->weightSum > 0.0) {
            // Switched off: the shown model's median from 5 indices on
            [$logDifficulty, $posteriorVariance] = [log($inputs->difficultyScore), 1.0 / $evidence->weightSum];
        } else {
            [$logDifficulty, $posteriorVariance] = $evidence->posterior($priorLog ?? 0.0, $priorVariance);
        }

        $group = PiecesGroup::fromPiecesCount($inputs->piecesCount);
        $kBucket = WindowBucket::kBucket($k);
        $offset = $features->on('centre_offsets')
            ? $parameters->centreOffset(self::offsetKeys($group, $paceSource->value, $kBucket, $priorType)) + $parameters->experienceOffset($inputs->candidateFirstTries)
            : 0.0;
        $unboxed = $features->on('unboxed') && $conditions->unboxed;
        [$taste, $tasteVariance] = self::taste($inputs, $parameters, $conditions->competition);
        $centre = exp($logPace + $logDifficulty + $offset + $taste + ($unboxed ? $parameters->unboxedOffset : 0.0));

        $dispersion = $features->on('dispersion') ? $evidence->dispersion($parameters->dispersionShrink) : 0.0;
        $contextScale = $parameters->contextScale($features->on('competition') && $conditions->competition, $unboxed);
        $pictureTerm = $k === 0 ? self::pictureTerm($inputs, $parameters) : 0.0;
        $sigma = $parameters->firstTrySigma($spread, $posteriorVariance, $group, $paceSource, $dispersion, $contextScale, $features->on('centre_offsets') ? $inputs->candidateFirstTries : null, $paceVariance, $tasteVariance, $pictureTerm + ($unboxed ? $parameters->unboxedVariance : 0.0));
        $keys = self::firstTryKeys(WindowBucket::quartile($spread, $parameters->consistencyEdges), $kBucket, $group, $features->on('source_quantiles') ? $priorType : null, self::paceKey($paceSource, $inputs->piecesCount));
        $window = TimePredictionCalculator::window($centre, $sigma, $parameters->quantilesFor($keys));


        return new TimePredictionResult(
            predictedSeconds: TimePredictionCalculator::seconds($centre),
            rangeLowSeconds: $window[1],
            rangeHighSeconds: $window[2],
            difficultyForPlayer: round(exp($logDifficulty), 4),
            range80LowSeconds: $window[0],
            range80HighSeconds: $window[3],
            difficultySource: match (true) {
                $k >= TimePredictionCalculator::RATED_INDICES => DifficultySource::Solves,
                $k === 0 && $priorType === ImageEstimatePrior::NAME => DifficultySource::Image,
                default => DifficultySource::Shrunk,
            },
            kIndices: $k,
            priorLog: $priorLog,
            logDifficulty: round($logDifficulty, 6),
            windowBucket: $keys[0],
            paceSource: $paceSource,
        );
    }

    /**
     * The variance of a prior type around the puzzle's difficulty: the fitted one per type (or one for all, switch
     * prior_types); an image estimate's from its own interval (v6, switch image_interval -
     * CandidateModelParameters::imagePriorVariance()).
     */
    public static function priorVarianceOf(CandidateModelParameters $parameters, string $priorType, null|float $estimateVariance): float
    {
        if ($parameters->features()->on('prior_types') === false) {
            return $parameters->priorVariance('pooled');
        }

        return $priorType === ImageEstimatePrior::NAME && $estimateVariance !== null
            ? $parameters->imagePriorVariance($estimateVariance)
            : $parameters->priorVariance($priorType);
    }

    /**
     * The window's picture term of a puzzle without indices (v6, switch picture_dispersion): how alike its pieces look
     * (PictureDispersionModel) - 0 without the model or the numbers.
     */
    public static function pictureTerm(StatisticalPredictionInputs $inputs, CandidateModelParameters $parameters): float
    {
        $model = $parameters->pictureDispersion;

        if ($model === null || $parameters->features()->on('picture_dispersion') === false) {
            return 0.0;
        }

        return $model->term($inputs->candidatePicture?->lookalikes);
    }

    /**
     * The pace a first try is predicted from: [log pace with its drift, the pace's own variance, the spread of its row,
     * what it rests on] - the size curve's pooled pace (SizeCurve::pace()) when the fit has one and the inputs carry the
     * player's rows, else the shown model's (TimePredictionCalculator::baselineFor(), variance 0). Null = no pace.
     *
     * @return null|array{0: float, 1: float, 2: float, 3: PaceSource}
     */
    public static function pace(StatisticalPredictionInputs $inputs, CandidateModelParameters $parameters): null|array
    {
        if ($parameters->sizeCurve !== null && $parameters->features()->on('size_curve') && $inputs->candidatePaceRows !== null) {
            $pooled = $parameters->sizeCurve->pace($inputs->piecesCount, $inputs->candidatePaceRows, $inputs->candidateFew);

            if ($pooled !== null) {
                return [$pooled[0], $pooled[1], $pooled[3], $pooled[2]];
            }
        }

        $baseline = TimePredictionCalculator::baselineFor($inputs, $parameters->paceParameters);

        if ($baseline === null || $baseline[0] <= 0) {
            return null;
        }

        return [log($baseline[0]) + $baseline[1], 0.0, $baseline[2] ?? PredictionModelParameters::GLOBAL_SPREAD, $baseline[3]];
    }

    /**
     * The player's taste term on the log centre and its variance (vᵀ A⁻¹ v) - [0, 0] while the fit has no taste model;
     * with one, a player without a state gets the prior's variance and a 0 term. At an event their own event offset
     * joins it (switch competition_player).
     *
     * @return array{0: float, 1: float}
     */
    public static function taste(StatisticalPredictionInputs $inputs, CandidateModelParameters $parameters, bool $competition): array
    {
        $model = $parameters->taste;

        if ($model === null || $parameters->features()->on('taste') === false) {
            return [0.0, 0.0];
        }

        $state = $inputs->candidateTaste ?? $model->initialState();
        $vector = $model->vector($inputs->candidatePicture, $inputs->piecesCount);
        $term = $state->term($vector);

        if ($competition && $parameters->features()->on('competition_player')) {
            $term += $state->competitionOffset($model->competitionShrink);
        }

        return [$term, $state->variance($vector)];
    }

    /**
     * A puzzle's candidate log difficulty from its evidence and its priors - the image estimate first, else the
     * puzzle-weighted brand prior, else the average puzzle (the size curve's own first tries).
     */
    public static function puzzleLogDifficulty(CandidateEvidence $evidence, null|float $imageLog, null|float $brandLog, CandidateModelParameters $parameters): float
    {
        $features = $parameters->features();
        [$type, $prior] = match (true) {
            $features->on('image_prior') && $imageLog !== null => [ImageEstimatePrior::NAME, $imageLog],
            $features->on('puzzle_weighted_brand') && $brandLog !== null => [BrandPiecesPrior::NAME, $brandLog],
            default => ['none', 0.0],
        };

        return $evidence->posterior($prior, $parameters->priorVariance($features->on('prior_types') ? $type : 'pooled'))[0];
    }

    /**
     * The window's own key of a size-curve pace from other sizes: what it rests on × the distance band from 500 (the
     * uncertainty of a pooled pace does not grow with the distance as the variance formula says - review "Size curve").
     */
    public static function paceKey(PaceSource $paceSource, int $piecesCount): null|string
    {
        return in_array($paceSource, [PaceSource::Pooled, PaceSource::PooledFew], true)
            ? sprintf('%s|d%d', $paceSource->value, SizeCurve::distanceBand($piecesCount))
            : null;
    }

    /**
     * A repeat: the shown model's centre × e^(gap offset), the candidate's window with the gap bucket.
     *
     * @param non-empty-list<int> $times
     */
    public function repeat(
        array $times,
        float $improvementRatio,
        null|float $repeatSpread,
        int $piecesCount,
        int $gapDays,
        CandidateModelParameters $parameters,
        PredictionModelParameters $shownParameters,
    ): TimePredictionResult {
        $shown = $this->shown->personal($times, $improvementRatio, $repeatSpread, $piecesCount, $shownParameters);

        return $this->repeatWindow($shown, $repeatSpread ?? PredictionModelParameters::GLOBAL_SPREAD, PiecesGroup::fromPiecesCount($piecesCount), $gapDays, $parameters);
    }

    /**
     * The candidate's window (and gap offset) around a repeat centre of the shown model.
     */
    public function repeatWindow(TimePredictionResult $shown, float $spread, PiecesGroup $group, int $gapDays, CandidateModelParameters $parameters): TimePredictionResult
    {
        $useGap = $parameters->features()->on('repeat_gap');
        $gap = $useGap ? TimePredictionCalculator::classifyGap($gapDays) : 'any';
        $centre = $shown->predictedSeconds * exp($parameters->repeatGapOffsets[$gap] ?? 0.0);
        $keys = self::repeatKeys(WindowBucket::quartile($spread, $parameters->repeatConsistencyEdges), $group, $gap);
        $window = TimePredictionCalculator::window($centre, $parameters->repeatSigma($spread, $gap), $parameters->quantilesFor($keys));

        return new TimePredictionResult(
            predictedSeconds: TimePredictionCalculator::seconds($centre),
            rangeLowSeconds: $window[1],
            rangeHighSeconds: $window[2],
            difficultyForPlayer: 0.0,
            isPersonalized: true,
            personalSolveCount: $shown->personalSolveCount,
            predictedAttemptNumber: $shown->predictedAttemptNumber,
            lastTimeSeconds: $shown->lastTimeSeconds,
            range80LowSeconds: $window[0],
            range80HighSeconds: $window[3],
            windowBucket: $keys[0],
        );
    }

    /**
     * The prior a first try's difficulty is shrunk toward: [type, log] - the image estimate, else brand × pieces
     * (puzzle-weighted, or the shown model's index-weighted one), else none (the average puzzle, 0.0).
     *
     * @return array{0: string, 1: null|float}
     */
    public function prior(StatisticalPredictionInputs $inputs, bool $imagePrior, bool $puzzleWeightedBrand): array
    {
        $estimate = $inputs->prior->imageEstimate;

        if ($imagePrior && $estimate !== null) {
            return [ImageEstimatePrior::NAME, $estimate->logDifficulty];
        }

        $brand = $puzzleWeightedBrand
            ? $inputs->candidateBrandPriorLog
            : new BrandPiecesPrior()->priorFor($inputs->prior)?->logDifficulty;

        return $brand !== null ? [BrandPiecesPrior::NAME, $brand] : ['none', null];
    }

    /**
     * Centre-offset keys, the most specific first: size group × pace source × k bucket (× prior type at k = 0), then
     * size group × pace source, then the size group.
     *
     * @return list<string>
     */
    public static function offsetKeys(PiecesGroup $group, string $paceSource, int $kBucket, string $priorType): array
    {
        $keys = [];

        if ($kBucket === 0) {
            $keys[] = sprintf('%s|%s|k0|%s', $group->value, $paceSource, $priorType);
        }

        $keys[] = sprintf('%s|%s|k%d', $group->value, $paceSource, $kBucket);
        $keys[] = sprintf('%s|%s', $group->value, $paceSource);
        $keys[] = $group->value;

        return $keys;
    }

    /**
     * First-try window keys, the most specific first ("v" = the candidate's first tries): consistency × k bucket ×
     * size group × prior type (when the source is in the keys), then without the prior type, without the size, the k
     * bucket alone, all.
     *
     * @return list<string>
     */
    public static function firstTryKeys(int $consistency, int $kBucket, PiecesGroup $group, null|string $priorType, null|string $paceKey = null): array
    {
        $keys = [];

        if ($paceKey !== null) {
            $keys[] = sprintf('v|pk|%s', $paceKey);
            $keys[] = sprintf('v|pk|%s', explode('|', $paceKey)[0]);
        }

        if ($priorType !== null && $kBucket <= 1) {
            $keys[] = sprintf('v|c%d|k%d|%s|%s', $consistency, $kBucket, $group->value, $priorType);
            $keys[] = sprintf('v|k%d|%s', $kBucket, $priorType);
        }

        $keys[] = sprintf('v|c%d|k%d|%s', $consistency, $kBucket, $group->value);
        $keys[] = sprintf('v|c%d|k%d', $consistency, $kBucket);
        $keys[] = sprintf('v|k%d', $kBucket);
        $keys[] = 'v';

        return $keys;
    }

    /**
     * Repeat window keys ("w"), the most specific first: consistency × size × gap, consistency × gap, gap, all.
     *
     * @return list<string>
     */
    public static function repeatKeys(int $consistency, PiecesGroup $group, string $gap): array
    {
        return [
            sprintf('w|c%d|%s|g%s', $consistency, $group->value, $gap),
            sprintf('w|c%d|g%s', $consistency, $gap),
            sprintf('w|g%s', $gap),
            'w',
        ];
    }
}
