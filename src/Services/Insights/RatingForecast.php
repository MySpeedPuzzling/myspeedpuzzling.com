<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use DateTimeImmutable;
use SpeedPuzzling\Web\Query\GetInsightsActivity;
use SpeedPuzzling\Web\Query\GetInsightsPaceHistory;
use SpeedPuzzling\Web\Results\Insights\LadderMilestone;
use SpeedPuzzling\Web\Results\Insights\PaceMilestone;
use SpeedPuzzling\Web\Results\Insights\PacePoint;
use SpeedPuzzling\Web\Results\Insights\RatingForecastLine;
use SpeedPuzzling\Web\Results\Insights\RatingForecastPoint;
use SpeedPuzzling\Web\Results\Insights\RatingForecastResult;
use SpeedPuzzling\Web\Results\Insights\ResultsMilestone;
use SpeedPuzzling\Web\Results\Insights\TargetTimeMilestone;
use SpeedPuzzling\Web\Services\Insights\Model\DecayTable;
use SpeedPuzzling\Web\Services\Insights\Model\PortfolioScore;
use SpeedPuzzling\Web\Services\Insights\Model\RatingModel;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingFormula;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Value\LeaderboardPosition;
use SpeedPuzzling\Web\Value\RatingForecastScenario;
use SpeedPuzzling\Web\Value\RatingStanding;

/**
 * "Where is my rating going?" (docs/features/insights/computation.md §4c.3, the prototype's renderForecast()): the rating
 * month by month for MONTHS months in three scenarios - stop solving (entries decay and leave the window), keep your
 * pace (the last 6 months' rated first tries again, a sixth of them a month), and the pace plus the 10 best moves -
 * and the milestones near the player: the next ladder place, the pace at the year's end, the next results count, the
 * next round time. Everything with MspRatingFormula over the stored entries; computed on read.
 */
readonly final class RatingForecast
{
    public const int MONTHS = 6;
    public const int BEST_MOVES = 10;
    public const int PACE_WINDOW_MONTHS = 6;
    public const int TARGET_STEP_SECONDS = 300;

    public function __construct(
        private RatingModelLoader $ratingModelLoader,
        private GetInsightsPaceHistory $getInsightsPaceHistory,
        private GetInsightsActivity $getInsightsActivity,
    ) {
    }

    public function build(string $playerId, int $piecesCount = PuzzleIntelligenceRecalculator::RATING_PIECES_COUNTS[0]): RatingForecastResult
    {
        return $this->forModel($playerId, $this->ratingModelLoader->load($playerId, $piecesCount));
    }

    /**
     * The forecast from a model the caller has already (RatingModelLoader) - two statements more. A page showing the
     * rating opportunities and the forecast in one lazy part builds the model once: RatingOpportunities::fromModel()
     * and this.
     */
    public function forModel(string $playerId, RatingModel $model): RatingForecastResult
    {
        if ($model->standing() === RatingStanding::Private || $model->standing() === RatingStanding::NoEntries) {
            return new RatingForecastResult($model->piecesCount, $model->standing(), 0.0, [], 0, null, null, null, null);
        }

        return self::fromInputs(
            $model,
            $this->getInsightsPaceHistory->pacePoints($playerId, $model->piecesCount),
            $this->getInsightsActivity->forPlayer($playerId, $model->piecesCount, $model->now),
        );
    }

    /**
     * @param list<PacePoint> $paces the player's pace history at the piece count, in time order
     * @param array{total: int, recent: int, recentTimes: list<int>} $activity
     */
    public static function fromInputs(RatingModel $model, array $paces, array $activity): RatingForecastResult
    {
        $now = $model->now;
        $since = $now->modify('-' . self::PACE_WINDOW_MONTHS . ' months');

        // The rated first tries of the last 6 months, oldest first: what "keep your pace" repeats
        $firstAttempts = [];
        $bestTimes = [];
        $firstAttemptDates = [];
        $resultDates = [];
        $recent = [];

        foreach ($model->solved as $puzzle) {
            if ($puzzle->firstAttemptEntry !== null) {
                $firstAttempts[$puzzle->puzzleId] = [$puzzle->firstAttemptEntry->rawPoints(), $puzzle->firstAttemptEntry->solvedAt];

                if ($puzzle->firstAttemptEntry->solvedAt >= $since) {
                    $recent[] = [$puzzle->firstAttemptEntry->rawPoints(), $puzzle->firstAttemptEntry->solvedAt, $puzzle->puzzleId];
                }
            }

            if ($puzzle->bestTimeEntry !== null) {
                $bestTimes[$puzzle->puzzleId] = [$puzzle->bestTimeEntry->rawPoints(), $puzzle->bestTimeEntry->solvedAt];
            }

            if ($puzzle->firstAttemptAt !== null && $puzzle->firstAttemptSeconds !== null) {
                $firstAttemptDates[] = $puzzle->firstAttemptAt;
            }

            $resultDates[] = $puzzle->latestAt;
        }

        usort($recent, static fn (array $a, array $b): int => $a[1] <=> $b[1] ?: strcmp($a[2], $b[2]));

        $decay = new DecayTable($now);
        $lines = [];

        foreach (RatingForecastScenario::cases() as $scenario) {
            $points = [];

            for ($month = 0; $month <= self::MONTHS; $month++) {
                $points[] = self::project($model, $scenario, $month, $firstAttempts, $bestTimes, $recent, $firstAttemptDates, $resultDates, $decay);
            }

            $last = $points[count($points) - 1];
            $lines[] = new RatingForecastLine($scenario, $points, $model->place($last->rating / RatingModel::DISPLAY_SCALE));
        }

        $defendBefore = MspRatingFormula::windowStart($now->modify('+' . RatingModel::DEFEND_WITHIN_DAYS . ' days'));
        $leaving = 0;

        foreach ([...array_values($firstAttempts), ...array_values($bestTimes)] as [, $solvedAt]) {
            if ($solvedAt < $defendBefore) {
                $leaving++;
            }
        }

        [$paceMilestone, $monthlySlope] = self::pace($model->piecesCount, $paces, $now);

        return new RatingForecastResult(
            piecesCount: $model->piecesCount,
            standing: $model->standing(),
            firstTriesPerMonth: count($recent) / self::PACE_WINDOW_MONTHS,
            lines: $lines,
            entriesLeavingWithin60Days: $leaving,
            ladder: self::ladder($model, $lines),
            pace: $paceMilestone,
            results: self::results($activity, $now),
            targetTime: self::targetTime($model->piecesCount, $paces, $activity['recentTimes'], $monthlySlope),
        );
    }

    /**
     * @param array<string, array{0: float, 1: DateTimeImmutable}> $firstAttempts puzzle id => (points before decay, solved)
     * @param array<string, array{0: float, 1: DateTimeImmutable}> $bestTimes
     * @param list<array{0: float, 1: DateTimeImmutable, 2: string}> $recent
     * @param list<DateTimeImmutable> $firstAttemptDates every first attempt at the piece count (progress)
     * @param list<DateTimeImmutable> $resultDates the latest solve of every puzzle at the piece count (progress)
     */
    private static function project(
        RatingModel $model,
        RatingForecastScenario $scenario,
        int $month,
        array $firstAttempts,
        array $bestTimes,
        array $recent,
        array $firstAttemptDates,
        array $resultDates,
        DecayTable $decay,
    ): RatingForecastPoint {
        $at = $decay->month($month);

        if ($month === 0) {
            return new RatingForecastPoint(0, $at, $model->rating() * RatingModel::DISPLAY_SCALE, $model->onLadder());
        }

        $windowStart = MspRatingFormula::windowStart($at);
        $firstAttemptPoints = [];
        $bestTimePoints = [];

        foreach ($firstAttempts as $puzzleId => [$raw, $solvedAt]) {
            if ($solvedAt >= $windowStart) {
                $firstAttemptPoints[$puzzleId] = $raw * $decay->at($solvedAt, $month);
            }
        }

        foreach ($bestTimes as $puzzleId => [$raw, $solvedAt]) {
            if ($solvedAt >= $windowStart) {
                $bestTimePoints[$puzzleId] = $raw * $decay->at($solvedAt, $month);
            }
        }

        $added = 0;

        if ($scenario !== RatingForecastScenario::Stop) {
            for ($played = 1; $played <= $month; $played++) {
                $playedAt = $decay->placedIn($played);

                foreach ($recent as $index => [$raw]) {
                    if ($index % self::PACE_WINDOW_MONTHS !== ($played - 1) % self::PACE_WINDOW_MONTHS) {
                        continue;
                    }

                    $points = $raw * $decay->at($playedAt, $month);
                    $firstAttemptPoints["pace-{$played}-{$index}"] = $points;
                    $bestTimePoints["pace-{$played}-{$index}"] = $points;
                    $added++;
                }
            }
        }

        if ($scenario === RatingForecastScenario::BestMoves) {
            $movedAt = $decay->placedIn(1);
            $moveDecay = $decay->at($movedAt, $month);

            foreach (array_slice($model->moveEffects, 0, self::BEST_MOVES) as $effect) {
                if ($effect['firstAttempt'] !== null) {
                    $firstAttemptPoints[$effect['puzzleId']] = $effect['firstAttempt'] * $moveDecay;
                    $added++;
                } elseif (isset($bestTimes[$effect['puzzleId']]) === false || $bestTimes[$effect['puzzleId']][1] < $windowStart) {
                    $added++; // a re-solve of a puzzle out of the window is a result in it again
                }

                $bestTimePoints[$effect['puzzleId']] = $effect['bestTime'] * $moveDecay;
            }
        }

        $firstAttemptScore = new PortfolioScore($firstAttemptPoints);
        $bestTimeScore = new PortfolioScore($bestTimePoints);

        $progressFirstAttempts = $added;
        $progressResults = $added;

        foreach ($firstAttemptDates as $solvedAt) {
            if ($solvedAt >= $windowStart) {
                $progressFirstAttempts++;
            }
        }

        foreach ($resultDates as $solvedAt) {
            if ($solvedAt >= $windowStart) {
                $progressResults++;
            }
        }

        return new RatingForecastPoint(
            monthsAhead: $month,
            date: $at,
            rating: PortfolioScore::rating($firstAttemptScore->mean(), $bestTimeScore->mean(), $firstAttemptScore->count() > 0) * RatingModel::DISPLAY_SCALE,
            onLadder: $model->optedOut === false
                && $model->private === false
                && $firstAttemptScore->count() >= MspRatingCalculator::MINIMUM_FIRST_ATTEMPTS
                && $progressFirstAttempts >= MspRatingCalculator::MINIMUM_FIRST_ATTEMPTS
                && $progressResults >= MspRatingCalculator::MINIMUM_TOTAL_SOLVES,
        );
    }

    /**
     * @param list<RatingForecastLine> $lines
     */
    private static function ladder(RatingModel $model, array $lines): null|LadderMilestone
    {
        if ($model->onLadder() === false) {
            return null;
        }

        $rating = $model->rating();
        $place = $model->place($rating);
        $target = null;

        foreach (array_reverse(LeaderboardPosition::RANK_MILESTONES) as $milestone) {
            if ($milestone < $place && $milestone <= count($model->ladder)) {
                $target = $milestone;

                break;
            }
        }

        if ($target === null) {
            return null;
        }

        // RANK(): place ≤ target once no more than target - 1 others are rated higher
        $needed = $model->ladder[$target - 1];
        $reachedBy = null;

        foreach ($lines as $line) {
            if ($line->scenario !== RatingForecastScenario::Stop && $line->end()->rating / RatingModel::DISPLAY_SCALE >= $needed) {
                $reachedBy = $line->scenario;

                break;
            }
        }

        return new LadderMilestone(
            place: $place,
            targetPlace: $target,
            ratingNeeded: $needed * RatingModel::DISPLAY_SCALE,
            gap: ($needed - $rating) * RatingModel::DISPLAY_SCALE,
            reachedBy: $reachedBy,
        );
    }

    /**
     * @param list<PacePoint> $paces
     *
     * @return array{0: null|PaceMilestone, 1: null|float} the milestone, the monthly log slope of the pace
     */
    private static function pace(int $piecesCount, array $paces, DateTimeImmutable $now): array
    {
        $rows = array_values(array_filter($paces, static fn (PacePoint $pace): bool => $pace->date <= $now));

        if ($rows === []) {
            return [null, null];
        }

        $latest = $rows[count($rows) - 1];
        $yearAgo = null;
        $yearAgoDate = $now->modify('-1 year');

        foreach ($rows as $row) {
            if ($row->date <= $yearAgoDate) {
                $yearAgo = $row;
            }
        }

        if ($yearAgo === null || $yearAgo->paceSeconds <= 0 || $latest->paceSeconds <= 0) {
            return [null, null];
        }

        $slope = log($latest->paceSeconds / $yearAgo->paceSeconds) / 12.0;
        $yearEnd = new DateTimeImmutable($now->format('Y') . '-12-31 23:59:59');

        if ($now->diff($yearEnd)->days < 31) {
            $yearEnd = $yearEnd->modify('+1 year');
        }

        $months = $now->diff($yearEnd)->days / 30.44;

        return [
            new PaceMilestone(
                piecesCount: $piecesCount,
                paceSeconds: $latest->paceSeconds,
                paceYearAgoSeconds: $yearAgo->paceSeconds,
                changePerYear: exp($slope * 12.0) - 1.0,
                yearEnd: $yearEnd,
                projectedSeconds: (int) round($latest->paceSeconds * exp($slope * $months)),
            ),
            $slope,
        ];
    }

    /**
     * @param array{total: int, recent: int, recentTimes: list<int>} $activity
     */
    private static function results(array $activity, DateTimeImmutable $now): null|ResultsMilestone
    {
        if ($activity['total'] === 0) {
            return null;
        }

        $total = $activity['total'];
        $step = match (true) {
            $total < 250 => 50,
            $total < 1000 => 100,
            $total < 5000 => 250,
            default => 500,
        };
        $next = (intdiv($total, $step) + 1) * $step;
        $perMonth = $activity['recent'] / (GetInsightsActivity::RECENT_DAYS / 30.44);
        $expectedAt = $perMonth > 0.0
            ? $now->modify('+' . (int) ceil(($next - $total) / $perMonth * 30.44) . ' days')
            : null;

        return new ResultsMilestone($total, $next, $perMonth, $expectedAt);
    }

    /**
     * @param list<PacePoint> $paces
     * @param list<int> $recentTimes
     */
    private static function targetTime(int $piecesCount, array $paces, array $recentTimes, null|float $monthlySlope): null|TargetTimeMilestone
    {
        if ($paces === [] || count($recentTimes) < 3) {
            return null;
        }

        $pace = $paces[count($paces) - 1]->paceSeconds;
        $target = intdiv($pace, self::TARGET_STEP_SECONDS) * self::TARGET_STEP_SECONDS;

        if ($target >= $pace - 30) {
            $target -= self::TARGET_STEP_SECONDS;
        }

        if ($target <= 0) {
            return null;
        }

        $under = count(array_filter($recentTimes, static fn (int $seconds): bool => $seconds < $target));
        $projected = null;

        if ($monthlySlope !== null) {
            $factor = exp($monthlySlope * self::MONTHS);
            $projected = count(array_filter($recentTimes, static fn (int $seconds): bool => $seconds * $factor < $target)) / count($recentTimes);
        }

        return new TargetTimeMilestone($piecesCount, $target, count($recentTimes), $under, $projected);
    }
}
