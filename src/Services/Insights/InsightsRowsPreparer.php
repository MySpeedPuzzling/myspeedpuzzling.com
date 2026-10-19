<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Results\InsightsSoloResult;
use SpeedPuzzling\Web\Value\InsightsTimeOfDay;

/**
 * Derives what every section needs from the player's solo results, once, over all of them (filters come after: "your
 * usual" at a moment is your usual, whatever the page filters). Definitions with their reasons:
 * docs/features/insights/computation.md "Core sections" §5 - the same ratio, form and residual as the profile card.
 */
readonly final class InsightsRowsPreparer
{
    public const int FORM_HALF_WINDOW = 75 * InsightsMath::DAY;

    /**
     * @param list<InsightsSoloResult> $results oldest first (GetInsightsSoloResults' order)
     * @param null|DateTimeZone $timezone the player's own zone; without one the logging day is UTC's and there is no time of day
     * @return list<InsightsRow> in the same order
     */
    public function prepare(array $results, LearningFactors $learningFactors, null|DateTimeZone $timezone): array
    {
        $utc = new DateTimeZone('UTC');
        $ratios = [];
        $moments = [];
        $points = [];

        foreach ($results as $i => $result) {
            $ratios[$i] = $result->ratio();
            $moments[] = $result->solvedAt->getTimestamp();

            // Your usual: boxed first tries only - unboxed is another discipline (the profile card's rule)
            if ($result->firstAttempt && $result->unboxed === false && $ratios[$i] !== null) {
                $points[] = [$moments[$i], $ratios[$i]];
            }
        }

        $forms = InsightsMath::rollingMedian($points, $moments, self::FORM_HALF_WINDOW);

        $rows = [];
        $latestDate = null;
        $perDate = [];

        foreach ($results as $i => $result) {
            $ratio = $ratios[$i];
            $form = $forms[$i];
            $residual = $ratio !== null && $form !== null && $form > 0
                ? $ratio / $form / $learningFactors->factor($result->piecesCount, $result->attempt)
                : null;

            $logged = $result->trackedAt->setTimezone($timezone ?? $utc);
            $day = $result->finishedAt ?? $logged;
            $solveDate = $day->format('Y-m-d');

            $timeOfDay = null;

            // "When you logged": only with your zone, and only where the logging day is the solve day
            if ($timezone !== null && ($result->finishedAt === null || $result->finishedAt->format('Y-m-d') === $logged->format('Y-m-d'))) {
                $timeOfDay = InsightsTimeOfDay::ofHour((int) $logged->format('G'));
            }

            $daysSincePrevious = $latestDate === null ? null : max(0, self::daysBetween($latestDate, $solveDate));

            if ($latestDate === null || $solveDate > $latestDate) {
                $latestDate = $solveDate;
            }

            $sameDayIndex = $perDate[$solveDate] ?? 0;
            $perDate[$solveDate] = $sameDayIndex + 1;

            $rows[] = new InsightsRow(
                result: $result,
                ratio: $ratio,
                form: $form,
                residual: $residual,
                solveDate: $solveDate,
                solveMoment: $moments[$i],
                weekday: (int) $day->format('N'),
                timeOfDay: $timeOfDay,
                daysSincePrevious: $daysSincePrevious,
                sameDayIndex: $sameDayIndex,
            );
        }

        return $rows;
    }

    private static function daysBetween(string $from, string $to): int
    {
        $fromDate = new DateTimeImmutable($from . ' 00:00:00', new DateTimeZone('UTC'));
        $toDate = new DateTimeImmutable($to . ' 00:00:00', new DateTimeZone('UTC'));

        return (int) round(($toDate->getTimestamp() - $fromDate->getTimestamp()) / InsightsMath::DAY);
    }
}
