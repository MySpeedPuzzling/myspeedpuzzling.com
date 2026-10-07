<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SuspiciousTimes;

use SpeedPuzzling\Web\Value\ExpectedTimeSource;
use SpeedPuzzling\Web\Value\PaceReference;
use SpeedPuzzling\Web\Value\SuspicionAssessment;
use SpeedPuzzling\Web\Value\SuspicionCheckOutcome;
use SpeedPuzzling\Web\Value\SuspicionEvidence;
use SpeedPuzzling\Web\Value\SuspicionInput;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use SpeedPuzzling\Web\Value\SuspiciousTimeTier;

/**
 * Judges one time against what this player does (docs/features/suspicious-time-review.md, "Detection").
 *
 * Solo: the expected time is the first available of the prediction (made without knowledge of the solve), the
 * player's baseline for the piece count × the puzzle's difficulty, the player's pace on their other solo results
 * within ±180 days. R = expected ÷ entered:
 *
 *   fast  prediction        raised from R 2.0 (beyond the model's p99.9), strong from 2.5
 *         baseline / pace   raised from R 2.5 (a looser model needs a higher bar), strong from 3.0
 *         either            only when the pace is at least the community median of its range - slower than a
 *                           typical puzzler is never a typo of this time (a history with days counted is)
 *         none              raised (strong) when the pace is above the community's 99.9th percentile of its range
 *   slow  prediction        raised from 3× slower (the model's own p99.9 is 1.53×), strong from 10×
 *         baseline / pace   raised from 5× slower, strong from 10×
 *         none              raised (strong) when the pace is below a tenth of the community median of its range
 *
 * With an expected time only the ratios count - a consistently slow (or fast) player is judged by their own times,
 * never by the community. Without one (a new player) a solo time that is neither beyond nor below is no_data,
 * checked again once the player has a level.
 *
 * A personal prediction is built on the player's earlier attempts of the puzzle. When the attempt before was itself
 * far too slow (an earlier attempt has a pending or marked slow case, or it is SLOW_USUAL_RAISE_RATIO × the baseline /
 * pace expectation - without one, below the community's slow floor), the prediction is inflated by it and an honest
 * second attempt would look far too fast: such a time is judged as if it had no prediction, and a raise carries the
 * moderator hint prediction_from_slow_attempt.
 *
 * Pair/team results have no personal expectation: they are judged only by the community's slow floor of their range
 * and puzzling type (raised strong below it, clear otherwise). Every fast rule is solo-only.
 *
 * Explanations describe a raised time and suggest the fix; they never raise one. Fitting ones (a likely mistake:
 * hours left out, a group saved as solo, another edition; minutes typed into the hours box, days counted instead of
 * puzzling time) make it strong. Fast explanations are only looked for on fast times and slow ones on slow times, so
 * "hours left out" and "minutes in the hours box" never come together.
 *
 * Pure: everything travels in the input. Every threshold is a constant here and part of VERSION - bump it on any
 * change of a threshold, a signal or the expected-time chain, and run the scan with --dry-run --report first.
 */
final class SuspiciousTimeClassifier
{
    public const int VERSION = 1;

    public const float PREDICTION_RAISE_RATIO = 2.0;
    public const float PREDICTION_STRONG_RATIO = 2.5;
    public const float USUAL_RAISE_RATIO = 2.5;
    public const float USUAL_STRONG_RATIO = 3.0;
    // A fast raise needs a pace of at least this share of the community median of the piece-count range (solo): a
    // player whose own history counts days (a baseline of 40 h for 200 pieces) made an ordinary 42-minute solve look
    // 69× faster. A real typo - hours left out - always lands far above the median
    public const float FAST_MIN_COMMUNITY_MEDIAN_SHARE = 1.0;

    // entered ÷ expected
    public const float SLOW_PREDICTION_RAISE_RATIO = 3.0;
    public const float SLOW_USUAL_RAISE_RATIO = 5.0;
    public const float SLOW_STRONG_RATIO = 10.0;
    // Without an expected time (and for pair/team results): below this share of the community median pace
    public const float SLOW_FLOOR_SHARE = 0.1;

    // The pace fallback (Query\GetPlayerPaces): the player's other solo results within this many days of the time
    public const int PACE_WINDOW_DAYS = 180;
    public const int PACE_MIN_RESULTS = 5;

    // The community pace per piece-count range and puzzling type (suspicious_time_reference)
    public const int REFERENCE_MIN_SAMPLE = 30;
    public const float REFERENCE_TOP_PERCENTILE = 0.999;

    // A no_data outcome is checked again while the time was solved within this many days - a new player's first
    // result can't be judged yet, but it can once they have a level
    public const int NO_DATA_RECHECK_DAYS = 180;

    // hours_left_out: entered + 1..5 hours lands within this share of the expected time
    public const int HOURS_LEFT_OUT_MAX = 5;
    public const float HOURS_FIT_LOW = 0.75;
    public const float HOURS_FIT_HIGH = 1.33;

    // teammates_saved_group: another player's pair/team result of the puzzle that day, this close to the time
    public const int TEAMMATES_WINDOW_SECONDS = 120;

    // often_in_group: this many pair/team results, and the time fits the player's group pace
    public const int OFTEN_IN_GROUP_MIN_RESULTS = 3;
    public const float GROUP_FIT_LOW = 0.6;
    public const float GROUP_FIT_HIGH = 1.6;

    // other_edition: names at least this alike, and the time fits the player at that piece count
    public const float OTHER_EDITION_MIN_SIMILARITY = 0.6;
    public const float OTHER_EDITION_FIT_LOW = 0.6;
    public const float OTHER_EDITION_FIT_HIGH = 1.6;

    // fastest_on_puzzle: would be #1 among at least this many other solo results
    public const int FASTEST_MIN_OTHER_RESULTS = 5;

    // minutes_in_hours_box: H:M:00 read as H min M s fits the expected time this well...
    public const float MINUTES_SHIFT_FIT_LOW = 0.75;
    public const float MINUTES_SHIFT_FIT_HIGH = 1.33;
    // ...or, without an expected time, the community median time of the range and puzzling type this well
    public const float MINUTES_SHIFT_COMMUNITY_LOW = 0.5;
    public const float MINUTES_SHIFT_COMMUNITY_HIGH = 2.0;

    // includes_breaks: a time of a day or more
    public const int BREAKS_MIN_SECONDS = 86400;

    // new_player: fewer solo results than this besides the time
    public const int NEW_PLAYER_MIN_RESULTS = 5;

    /**
     * comment_mentions_group - conservative, whole words, case-insensitive, 6 languages. Japanese has no word
     * boundaries: スペア ("spare") is no pair. German "ein paar" means "a few".
     */
    public const array GROUP_WORD_PATTERNS = [
        '/\bteam\w*/iu',
        '/\bpair(?:s|ed)?\b/iu',
        '/\bduo\b/iu',
        '/\bpartners?\b/iu',
        '/\btogether\b/iu',
        '/\btým\w*/iu',
        '/\bdvojic\w*/iu',
        '/\bve\s+dvou\b/iu',
        '/(?<!ein\s)\bpaar\b/iu',
        '/\bzu\s+zweit\b/iu',
        '/\bequipos?\b/iu',
        '/\bparejas?\b/iu',
        '/\béquipes?\b/iu',
        '/\bbinômes?\b/iu',
        '/\bà\s+deux\b/iu',
        '/チーム/u',
        '/(?<!ス)ペア/u',
    ];

    public function classify(SuspicionInput $input): SuspicionAssessment
    {
        if ($input->seconds <= 0 || $input->piecesCount <= 0) {
            return new SuspicionAssessment(SuspicionCheckOutcome::NoData);
        }

        if ($input->isSolo() === false) {
            return $this->classifyGroup($input);
        }

        [$expectedSeconds, $source] = self::expectation($input);

        if ($expectedSeconds === null || $source === null) {
            return $this->classifyWithoutExpectation($input);
        }

        $ratio = $expectedSeconds / $input->seconds;
        $slowRatio = $input->seconds / $expectedSeconds;
        $againstPrediction = $source === ExpectedTimeSource::Prediction;

        if ($ratio >= ($againstPrediction ? self::PREDICTION_RAISE_RATIO : self::USUAL_RAISE_RATIO)) {
            if ($againstPrediction && self::predictionBuiltOnSlowAttempt($input)) {
                return $this->classifyWithoutTheInflatedPrediction($input, $expectedSeconds);
            }

            // Fast against the player's own times, yet slower than a typical puzzler of the range: the history is
            // what is off, not this time
            if (self::belowCommunityMedian($input)) {
                return new SuspicionAssessment(
                    outcome: SuspicionCheckOutcome::Clear,
                    ratio: $ratio,
                    expectedSeconds: $expectedSeconds,
                    expectedSource: $source,
                );
            }

            return $this->raisedFast($input, $expectedSeconds, $source, $ratio);
        }

        if ($slowRatio >= ($againstPrediction ? self::SLOW_PREDICTION_RAISE_RATIO : self::SLOW_USUAL_RAISE_RATIO)) {
            return $this->raisedSlow($input, $expectedSeconds, $source, $slowRatio);
        }

        return new SuspicionAssessment(
            outcome: SuspicionCheckOutcome::Clear,
            ratio: $ratio,
            expectedSeconds: $expectedSeconds,
            expectedSource: $source,
        );
    }

    /**
     * The first available of prediction, baseline, pace - solo times only.
     *
     * @return array{null|int, null|ExpectedTimeSource}
     */
    public static function expectation(SuspicionInput $input): array
    {
        if ($input->isSolo() === false) {
            return [null, null];
        }

        if ($input->predictedSeconds !== null && $input->predictedSeconds > 0) {
            return [$input->predictedSeconds, ExpectedTimeSource::Prediction];
        }

        if ($input->baselineSeconds !== null && $input->baselineSeconds > 0) {
            $difficulty = $input->difficultyScore !== null && $input->difficultyScore > 0 ? $input->difficultyScore : 1.0;

            return [(int) round($input->baselineSeconds * $difficulty), ExpectedTimeSource::Baseline];
        }

        $reference = $input->soloReference($input->piecesCount);

        if ($input->paceFactor !== null && $input->paceFactor > 0 && $reference !== null) {
            return [$reference->secondsAt($input->piecesCount, $input->paceFactor), ExpectedTimeSource::Pace];
        }

        return [null, null];
    }

    /**
     * The entered pace is below FAST_MIN_COMMUNITY_MEDIAN_SHARE of the community median of its piece-count range
     * (solo) - never raised as fast. Without a reference for the range nothing is known and the rule does not apply.
     */
    public static function belowCommunityMedian(SuspicionInput $input): bool
    {
        $reference = $input->soloReference($input->piecesCount);

        return $reference !== null
            && $input->seconds > 0
            && $input->piecesCount * 60 / $input->seconds < $reference->medianPpm * self::FAST_MIN_COMMUNITY_MEDIAN_SHARE;
    }

    /**
     * Whether the classifier needs the pace fallback for this solo time - the scan looks paces up only for these: no
     * prediction and no baseline, or (with the seconds and the attempt the prediction came from) a personal
     * prediction the time is far faster than, which may have to be replaced by the pace.
     */
    public static function needsPace(
        null|int $predictedSeconds,
        null|int $baselineSeconds,
        null|int $seconds = null,
        null|int $previousAttemptSeconds = null,
    ): bool {
        if ($baselineSeconds !== null && $baselineSeconds > 0) {
            return false;
        }

        if ($predictedSeconds === null || $predictedSeconds <= 0) {
            return true;
        }

        return $previousAttemptSeconds !== null
            && $seconds !== null
            && $seconds > 0
            && $predictedSeconds >= self::PREDICTION_RAISE_RATIO * $seconds;
    }

    /**
     * A personal prediction built on an attempt that is itself far too slow: an earlier attempt of the puzzle has a
     * slow case, or the attempt before is SLOW_USUAL_RAISE_RATIO × the baseline / pace expectation (without one: below
     * the community's slow floor for the piece count).
     */
    public static function predictionBuiltOnSlowAttempt(SuspicionInput $input): bool
    {
        if ($input->previousAttemptRaisedSlow) {
            return true;
        }

        $previous = $input->previousAttemptSeconds;

        if ($previous === null || $previous <= 0) {
            return false;
        }

        [$usual] = self::expectation($input->withoutPrediction());

        if ($usual !== null) {
            return $previous >= self::SLOW_USUAL_RAISE_RATIO * $usual;
        }

        $reference = $input->soloReference($input->piecesCount);

        return $reference !== null && $input->piecesCount * 60 / $previous < $reference->medianPpm * self::SLOW_FLOOR_SHARE;
    }

    /**
     * Median of a non-empty list (the pace fallback and the group pace share it).
     *
     * @param non-empty-list<float> $values
     */
    public static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * The number of hours that, added to the entered time, lands closest to the expected time - within the fit.
     */
    public static function hoursLeftOut(int $seconds, int $expectedSeconds): null|int
    {
        $best = null;
        $bestDistance = null;

        for ($hours = 1; $hours <= self::HOURS_LEFT_OUT_MAX; $hours++) {
            $fit = ($seconds + $hours * 3600) / $expectedSeconds;

            if ($fit < self::HOURS_FIT_LOW || $fit > self::HOURS_FIT_HIGH) {
                continue;
            }

            $distance = abs(log($fit));

            if ($bestDistance === null || $distance < $bestDistance) {
                $best = $hours;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * H:M:00 entered for H minutes M seconds - the minutes ended up in the hours box. The shifted reading must fit
     * the expected time, or without one lie near the community median time. Returns the shifted seconds.
     */
    public static function minutesInHoursBox(int $seconds, null|int $expectedSeconds, null|int $communityMedianSeconds): null|int
    {
        if ($seconds % 60 !== 0 || $seconds < 3600) {
            return null;
        }

        $shifted = intdiv($seconds, 3600) * 60 + intdiv($seconds % 3600, 60);

        if ($expectedSeconds !== null) {
            $fit = $shifted / $expectedSeconds;

            return $fit >= self::MINUTES_SHIFT_FIT_LOW && $fit <= self::MINUTES_SHIFT_FIT_HIGH ? $shifted : null;
        }

        if ($communityMedianSeconds !== null) {
            $fit = $shifted / $communityMedianSeconds;

            return $fit >= self::MINUTES_SHIFT_COMMUNITY_LOW && $fit <= self::MINUTES_SHIFT_COMMUNITY_HIGH ? $shifted : null;
        }

        return null;
    }

    /**
     * The first group word of the comment as written, or null.
     */
    public static function groupWordIn(null|string $comment): null|string
    {
        if ($comment === null || trim($comment) === '') {
            return null;
        }

        foreach (self::GROUP_WORD_PATTERNS as $pattern) {
            if (preg_match($pattern, $comment, $match) === 1) {
                return $match[0];
            }
        }

        return null;
    }

    /**
     * Judged by the baseline, the pace or the community instead - a raise says why the prediction was not used.
     */
    private function classifyWithoutTheInflatedPrediction(SuspicionInput $input, int $predictedSeconds): SuspicionAssessment
    {
        $assessment = $this->classify($input->withoutPrediction());

        if ($assessment->isRaised() === false) {
            return $assessment;
        }

        return $assessment->withReason(new SuspiciousTimeReason(SuspiciousTimeReasonCode::PredictionFromSlowAttempt, [
            'predicted' => $predictedSeconds,
            'previous' => $input->previousAttemptSeconds,
            'raised_slow' => $input->previousAttemptRaisedSlow,
        ]));
    }

    private function raisedFast(SuspicionInput $input, int $expectedSeconds, ExpectedTimeSource $source, float $ratio): SuspicionAssessment
    {
        $againstPrediction = $source === ExpectedTimeSource::Prediction;
        $params = [
            'expected' => $expectedSeconds,
            'entered' => $input->seconds,
            'ratio' => round($ratio, 2),
            'pieces' => $input->piecesCount,
        ];

        $trigger = $againstPrediction
            ? new SuspiciousTimeReason(SuspiciousTimeReasonCode::FasterThanPredicted, $params)
            : new SuspiciousTimeReason(SuspiciousTimeReasonCode::FasterThanUsual, [...$params, 'source' => $source->value]);

        [$explanations, $suggestedSeconds] = $this->fastExplanations($input, $expectedSeconds);
        $strongFrom = $againstPrediction ? self::PREDICTION_STRONG_RATIO : self::USUAL_STRONG_RATIO;

        return new SuspicionAssessment(
            outcome: SuspicionCheckOutcome::Raised,
            tier: $ratio >= $strongFrom || self::hasFittingExplanation($explanations) ? SuspiciousTimeTier::Strong : SuspiciousTimeTier::Possible,
            ratio: $ratio,
            expectedSeconds: $expectedSeconds,
            expectedSource: $source,
            reasons: [$trigger, ...$explanations, ...self::hints($input)],
            suggestedSeconds: $suggestedSeconds,
            score: $ratio,
        );
    }

    private function raisedSlow(SuspicionInput $input, int $expectedSeconds, ExpectedTimeSource $source, float $slowRatio): SuspicionAssessment
    {
        $params = [
            'expected' => $expectedSeconds,
            'entered' => $input->seconds,
            'ratio' => round($slowRatio, 2),
            'pieces' => $input->piecesCount,
        ];

        $trigger = $source === ExpectedTimeSource::Prediction
            ? new SuspiciousTimeReason(SuspiciousTimeReasonCode::SlowerThanPredicted, $params)
            : new SuspiciousTimeReason(SuspiciousTimeReasonCode::SlowerThanUsual, [...$params, 'source' => $source->value]);

        [$explanations, $suggestedSeconds] = self::slowExplanations($input->seconds, $expectedSeconds, null);

        return new SuspicionAssessment(
            outcome: SuspicionCheckOutcome::Raised,
            tier: $slowRatio >= self::SLOW_STRONG_RATIO || self::hasFittingExplanation($explanations) ? SuspiciousTimeTier::Strong : SuspiciousTimeTier::Possible,
            ratio: $expectedSeconds / $input->seconds,
            expectedSeconds: $expectedSeconds,
            expectedSource: $source,
            reasons: [$trigger, ...$explanations, ...self::hints($input)],
            suggestedSeconds: $suggestedSeconds,
            score: $slowRatio,
        );
    }

    /**
     * A player without times of their own: only the community's extremes of the range are a bar.
     */
    private function classifyWithoutExpectation(SuspicionInput $input): SuspicionAssessment
    {
        $reference = $input->reference();

        if ($reference === null) {
            return new SuspicionAssessment(SuspicionCheckOutcome::NoData);
        }

        $ppm = $input->piecesCount * 60 / $input->seconds;

        if ($ppm > $reference->p999Ppm) {
            $trigger = new SuspiciousTimeReason(SuspiciousTimeReasonCode::BeyondKnownPace, [
                'ppm' => round($ppm, 1),
                'p999_ppm' => round($reference->p999Ppm, 1),
                'entered' => $input->seconds,
                'pieces' => $input->piecesCount,
            ]);

            [$explanations, $suggestedSeconds] = $this->fastExplanations($input, null);

            return new SuspicionAssessment(
                outcome: SuspicionCheckOutcome::Raised,
                tier: SuspiciousTimeTier::Strong,
                reasons: [$trigger, ...$explanations, ...self::hints($input)],
                suggestedSeconds: $suggestedSeconds,
                score: $ppm / $reference->p999Ppm,
            );
        }

        if ($ppm < $reference->medianPpm * self::SLOW_FLOOR_SHARE) {
            return $this->raisedBelowFloor($input, $reference, $ppm);
        }

        return new SuspicionAssessment(SuspicionCheckOutcome::NoData);
    }

    /**
     * A pair/team result: no personal expectation, only the community's slow floor of its range and puzzling type.
     */
    private function classifyGroup(SuspicionInput $input): SuspicionAssessment
    {
        $reference = $input->reference();

        if ($reference === null) {
            return new SuspicionAssessment(SuspicionCheckOutcome::NoData);
        }

        $ppm = $input->piecesCount * 60 / $input->seconds;

        if ($ppm < $reference->medianPpm * self::SLOW_FLOOR_SHARE) {
            return $this->raisedBelowFloor($input, $reference, $ppm);
        }

        return new SuspicionAssessment(SuspicionCheckOutcome::Clear);
    }

    private function raisedBelowFloor(SuspicionInput $input, PaceReference $reference, float $ppm): SuspicionAssessment
    {
        $floorPpm = $reference->medianPpm * self::SLOW_FLOOR_SHARE;
        $medianSeconds = $reference->medianSeconds($input->piecesCount);

        $trigger = new SuspiciousTimeReason(SuspiciousTimeReasonCode::BelowSlowFloor, [
            'ppm' => round($ppm, 2),
            'floor_ppm' => round($floorPpm, 2),
            'median' => $medianSeconds,
            'entered' => $input->seconds,
            'pieces' => $input->piecesCount,
            'puzzling_type' => $input->puzzlingType->value,
        ]);

        [$explanations, $suggestedSeconds] = self::slowExplanations($input->seconds, null, $medianSeconds);

        return new SuspicionAssessment(
            outcome: SuspicionCheckOutcome::Raised,
            tier: SuspiciousTimeTier::Strong,
            reasons: [$trigger, ...$explanations, ...self::hints($input)],
            suggestedSeconds: $suggestedSeconds,
            score: $floorPpm / $ppm,
        );
    }

    /**
     * @return array{list<SuspiciousTimeReason>, null|int} the explanations and the suggested time
     */
    private function fastExplanations(SuspicionInput $input, null|int $expectedSeconds): array
    {
        $reasons = [];
        $suggestedSeconds = null;
        $evidence = $input->evidence;

        if ($expectedSeconds !== null) {
            $hours = self::hoursLeftOut($input->seconds, $expectedSeconds);

            if ($hours !== null) {
                $suggestedSeconds = $input->seconds + $hours * 3600;
                // entered: the suggestion belongs to this entry - an edited time makes it stale
                $reasons[] = new SuspiciousTimeReason(SuspiciousTimeReasonCode::HoursLeftOut, [
                    'suggested' => $suggestedSeconds,
                    'hours' => $hours,
                    'entered' => $input->seconds,
                ]);
            }
        }

        if ($evidence === null) {
            return [$reasons, $suggestedSeconds];
        }

        $teammates = self::teammatesGroupResult($input->seconds, $evidence);

        if ($teammates !== null) {
            $reasons[] = new SuspiciousTimeReason(SuspiciousTimeReasonCode::TeammatesSavedGroup, [
                'time_id' => $teammates['time_id'],
                'seconds' => $teammates['seconds'],
                'puzzling_type' => $teammates['puzzling_type'],
            ]);
        }

        $word = self::groupWordIn($evidence->comment);

        if ($word !== null) {
            $reasons[] = new SuspiciousTimeReason(SuspiciousTimeReasonCode::CommentMentionsGroup, ['word' => $word]);
        }

        $groupExpected = self::groupExpectedSeconds($input, $evidence);

        if ($groupExpected !== null) {
            $fit = $input->seconds / $groupExpected;

            if ($fit >= self::GROUP_FIT_LOW && $fit <= self::GROUP_FIT_HIGH) {
                $reasons[] = new SuspiciousTimeReason(SuspiciousTimeReasonCode::OftenInGroup, [
                    'group_results' => count($evidence->groupResults),
                    'group_expected' => $groupExpected,
                ]);
            }
        }

        if ($expectedSeconds !== null) {
            $edition = self::otherEdition($input, $evidence, $expectedSeconds);

            if ($edition !== null) {
                $reasons[] = new SuspiciousTimeReason(SuspiciousTimeReasonCode::OtherEdition, [
                    'puzzle_id' => $edition['puzzle_id'],
                    'name' => $edition['name'],
                    'pieces' => $edition['pieces'],
                ]);
            }
        }

        if (
            $evidence->otherResultsOnPuzzle >= self::FASTEST_MIN_OTHER_RESULTS
            && $evidence->fastestOtherSeconds !== null
            && $input->seconds < $evidence->fastestOtherSeconds
        ) {
            $reasons[] = new SuspiciousTimeReason(SuspiciousTimeReasonCode::FastestOnPuzzle, [
                'results' => $evidence->otherResultsOnPuzzle,
                'fastest' => $evidence->fastestOtherSeconds,
            ]);
        }

        return [$reasons, $suggestedSeconds];
    }

    /**
     * @return array{list<SuspiciousTimeReason>, null|int} the explanations and the suggested time
     */
    private static function slowExplanations(int $seconds, null|int $expectedSeconds, null|int $communityMedianSeconds): array
    {
        $reasons = [];
        $suggestedSeconds = self::minutesInHoursBox($seconds, $expectedSeconds, $communityMedianSeconds);

        if ($suggestedSeconds !== null) {
            $reasons[] = new SuspiciousTimeReason(SuspiciousTimeReasonCode::MinutesInHoursBox, ['suggested' => $suggestedSeconds, 'entered' => $seconds]);
        }

        if ($seconds >= self::BREAKS_MIN_SECONDS) {
            $reasons[] = new SuspiciousTimeReason(SuspiciousTimeReasonCode::IncludesBreaks, ['entered' => $seconds]);
        }

        return [$reasons, $suggestedSeconds];
    }

    /**
     * Moderator-only context of a raised solo time, either direction.
     *
     * @return list<SuspiciousTimeReason>
     */
    private static function hints(SuspicionInput $input): array
    {
        $evidence = $input->evidence;

        if ($evidence === null || $input->isSolo() === false) {
            return [];
        }

        $hints = [];

        if ($evidence->otherSoloResults < self::NEW_PLAYER_MIN_RESULTS) {
            $hints[] = new SuspiciousTimeReason(SuspiciousTimeReasonCode::NewPlayer, ['results' => $evidence->otherSoloResults]);
        }

        if ($evidence->confirmedExpectedSeconds !== null) {
            $hints[] = new SuspiciousTimeReason(SuspiciousTimeReasonCode::ConfirmedWhileSaving, ['expected' => $evidence->confirmedExpectedSeconds]);
        }

        return $hints;
    }

    /**
     * @param list<SuspiciousTimeReason> $explanations
     */
    private static function hasFittingExplanation(array $explanations): bool
    {
        foreach ($explanations as $explanation) {
            if ($explanation->code->isFittingExplanation()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return null|array{time_id: string, seconds: int, puzzling_type: string}
     */
    private static function teammatesGroupResult(int $seconds, SuspicionEvidence $evidence): null|array
    {
        $closest = null;

        foreach ($evidence->sameDayGroupResults as $result) {
            $gap = abs($result['seconds'] - $seconds);

            if ($gap > self::TEAMMATES_WINDOW_SECONDS) {
                continue;
            }

            if ($closest === null || $gap < abs($closest['seconds'] - $seconds)) {
                $closest = $result;
            }
        }

        return $closest;
    }

    /**
     * The person's group pace at this piece count: the median relative pace of their pair/team results, made
     * comparable by the community solo pace of each result's range.
     */
    private static function groupExpectedSeconds(SuspicionInput $input, SuspicionEvidence $evidence): null|int
    {
        $reference = $input->soloReference($input->piecesCount);

        if ($reference === null || count($evidence->groupResults) < self::OFTEN_IN_GROUP_MIN_RESULTS) {
            return null;
        }

        $paces = [];

        foreach ($evidence->groupResults as $result) {
            $resultReference = $input->soloReference($result['pieces']);

            if ($resultReference !== null && $result['seconds'] > 0) {
                $paces[] = $resultReference->relativePace($result['pieces'], $result['seconds']);
            }
        }

        if (count($paces) < self::OFTEN_IN_GROUP_MIN_RESULTS) {
            return null;
        }

        return $reference->secondsAt($input->piecesCount, self::median($paces));
    }

    /**
     * The edition the time fits best: the expectation carried over to its piece count by the community medians.
     *
     * @return null|array{puzzle_id: string, name: string, pieces: int, similarity: float}
     */
    private static function otherEdition(SuspicionInput $input, SuspicionEvidence $evidence, int $expectedSeconds): null|array
    {
        $reference = $input->soloReference($input->piecesCount);

        if ($reference === null) {
            return null;
        }

        $best = null;
        $bestDistance = null;

        foreach ($evidence->otherEditions as $edition) {
            $editionReference = $input->soloReference($edition['pieces']);

            if ($edition['similarity'] < self::OTHER_EDITION_MIN_SIMILARITY || $editionReference === null) {
                continue;
            }

            // The player's relative pace kept, the piece count and its community median changed
            $expectedThere = $expectedSeconds * ($edition['pieces'] / $input->piecesCount) * ($reference->medianPpm / $editionReference->medianPpm);
            $fit = $input->seconds / $expectedThere;

            if ($fit < self::OTHER_EDITION_FIT_LOW || $fit > self::OTHER_EDITION_FIT_HIGH) {
                continue;
            }

            $distance = abs(log($fit));

            if ($bestDistance === null || $distance < $bestDistance) {
                $best = $edition;
                $bestDistance = $distance;
            }
        }

        return $best;
    }
}
