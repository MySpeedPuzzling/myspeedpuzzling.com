<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Query\GetInsightsPaceHistory;
use SpeedPuzzling\Web\Results\Insights\ImprovementCurveResult;
use SpeedPuzzling\Web\Results\Insights\ImprovementCurveSize;
use SpeedPuzzling\Web\Results\Insights\PaceHistoryRow;
use SpeedPuzzling\Web\Results\Insights\PaceJump;
use SpeedPuzzling\Web\Results\Insights\PacePlateau;
use SpeedPuzzling\Web\Results\Insights\PacePoint;
use SpeedPuzzling\Web\Results\Insights\TypicalPacePoint;

/**
 * "Am I getting better, and when did it happen?" from the pace history (docs/features/insights/computation.md §4c.5,
 * prediction-research.md §4): the pace curve per piece count, the biggest jumps (a solve that moved the pace more
 * than 3 % faster at once, with that solve and its puzzle), a plateau (the pace within ±2 % of today's for 20+ first
 * tries over 60+ days) and, at 500 pieces, the typical newcomer's curve from the same first pace. One statement.
 */
readonly final class ImprovementCurve
{
    /** ln(0.97): a step at least this negative is a jump */
    public const float JUMP_STEP = -0.030459207484708574;
    public const int JUMPS_SHOWN = 5;
    public const float PLATEAU_BAND = 0.02;
    public const int PLATEAU_MINIMUM_FIRST_TRIES = 20;
    public const int PLATEAU_MINIMUM_DAYS = 60;

    /**
     * The typical change of the 500-piece pace against the first one after N qualifying first attempts (production
     * copy 2026-10, measured from the 6th solve - prediction-research.md §4). Log-linear between the points, not beyond
     * the last one.
     */
    public const array TYPICAL_500 = [5 => 0.0, 25 => -0.045, 50 => -0.076, 100 => -0.122, 150 => -0.150, 300 => -0.210];

    public function __construct(
        private GetInsightsPaceHistory $getInsightsPaceHistory,
    ) {
    }

    public function build(string $playerId): ImprovementCurveResult
    {
        return self::fromRows($this->getInsightsPaceHistory->forPlayer($playerId));
    }

    /**
     * @param list<PaceHistoryRow> $rows by piece count, in time order
     */
    public static function fromRows(array $rows): ImprovementCurveResult
    {
        /** @var array<int, non-empty-list<PaceHistoryRow>> $bySize */
        $bySize = [];

        foreach ($rows as $row) {
            $bySize[$row->piecesCount][] = $row;
        }

        $sizes = [];

        foreach ($bySize as $piecesCount => $sizeRows) {
            $sizes[] = self::size($piecesCount, $sizeRows);
        }

        usort($sizes, static fn (ImprovementCurveSize $a, ImprovementCurveSize $b): int => $b->firstTries <=> $a->firstTries ?: $a->piecesCount <=> $b->piecesCount);

        return new ImprovementCurveResult($sizes);
    }

    /**
     * The typical change after $firstTries first attempts at 500 pieces; null beyond the measured range.
     */
    public static function typicalChange(int $firstTries): null|float
    {
        $previousCount = null;
        $previousChange = null;

        foreach (self::TYPICAL_500 as $count => $change) {
            if ($firstTries === $count) {
                return $change;
            }

            if ($firstTries < $count) {
                if ($previousCount === null || $previousChange === null) {
                    return null;
                }

                $share = (log($firstTries) - log($previousCount)) / (log($count) - log($previousCount));

                return $previousChange + $share * ($change - $previousChange);
            }

            $previousCount = $count;
            $previousChange = $change;
        }

        return null;
    }

    /**
     * @param non-empty-list<PaceHistoryRow> $rows
     */
    private static function size(int $piecesCount, array $rows): ImprovementCurveSize
    {
        $first = $rows[0];
        $last = $rows[count($rows) - 1];

        /** @var array<string, PacePoint> $daily */
        $daily = [];
        $jumps = [];
        $steps = 0;

        foreach ($rows as $row) {
            $daily[$row->validFrom->format('Y-m-d')] = new PacePoint($row->validFrom, $row->qualifyingCount, $row->baselineSeconds);

            if ($row->step === null) {
                continue;
            }

            $steps++;

            if ($row->step <= self::JUMP_STEP) {
                $jumps[] = new PaceJump(
                    date: $row->validFrom,
                    timeId: $row->timeId,
                    puzzle: $row->puzzle,
                    solveSeconds: $row->solveSeconds,
                    paceBeforeSeconds: (int) round($row->baselineSeconds / exp($row->step)),
                    paceAfterSeconds: $row->baselineSeconds,
                    change: exp($row->step) - 1.0,
                );
            }
        }

        $jumpsCount = count($jumps);
        usort($jumps, static fn (PaceJump $a, PaceJump $b): int => $a->change <=> $b->change ?: $a->date <=> $b->date);

        $typicalCurve = [];
        $typicalChange = null;

        if ($piecesCount === 500 && $first->baselineSeconds > 0) {
            foreach (self::TYPICAL_500 as $count => $change) {
                if ($count >= $first->qualifyingCount && $count <= $last->qualifyingCount) {
                    $typicalCurve[] = new TypicalPacePoint($count, (int) round($first->baselineSeconds * (1.0 + $change)));
                }
            }

            $typicalChange = self::typicalChange($last->qualifyingCount);
        }

        return new ImprovementCurveSize(
            piecesCount: $piecesCount,
            points: array_values($daily),
            firstPaceSeconds: $first->baselineSeconds,
            paceSeconds: $last->baselineSeconds,
            firstTries: $last->qualifyingCount,
            changeSinceFirst: $first->baselineSeconds > 0 ? $last->baselineSeconds / $first->baselineSeconds - 1.0 : 0.0,
            typicalChange: $typicalChange,
            typicalCurve: $typicalCurve,
            biggestJumps: array_slice($jumps, 0, self::JUMPS_SHOWN),
            jumpsCount: $jumpsCount,
            stepsCount: $steps,
            plateau: self::plateau($rows),
        );
    }

    /**
     * @param non-empty-list<PaceHistoryRow> $rows
     */
    private static function plateau(array $rows): null|PacePlateau
    {
        $last = $rows[count($rows) - 1];

        if ($last->baselineSeconds <= 0) {
            return null;
        }

        $since = $last;
        $count = 0;

        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $row = $rows[$i];

            if ($row->baselineSeconds <= 0 || abs(log($row->baselineSeconds / $last->baselineSeconds)) > self::PLATEAU_BAND) {
                break;
            }

            $since = $row;
            $count++;
        }

        if ($count < self::PLATEAU_MINIMUM_FIRST_TRIES || $since->validFrom->diff($last->validFrom)->days < self::PLATEAU_MINIMUM_DAYS) {
            return null;
        }

        return new PacePlateau($since->validFrom, $count, $last->baselineSeconds);
    }
}
