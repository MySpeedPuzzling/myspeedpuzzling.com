<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\Insights\InsightsResultItem;
use SpeedPuzzling\Web\Results\InsightsSoloResult;
use SpeedPuzzling\Web\Value\InsightsSituation;
use SpeedPuzzling\Web\Value\InsightsTimeOfDay;

/**
 * One solo result with what the builders derive for it once (InsightsRowsPreparer): its ratio to the puzzle median,
 * "your usual" at that moment, the residual, its solve day and how it sits in the player's timeline. Internal to the
 * builders - not a service.
 */
readonly final class InsightsRow
{
    /**
     * @param null|float $ratio time ÷ the puzzle's median solo time
     * @param null|float $form your usual then: median ratio of your boxed first tries within ±75 days
     * @param null|float $residual ratio ÷ form ÷ the community's learning factor - 1.0 = exactly your usual
     * @param string $solveDate Y-m-d - the date you gave, else the logging day in your zone (UTC without one)
     * @param int $weekday ISO-8601 day of solveDate
     * @param null|int $daysSincePrevious days since the previous solo result's solve day, null for the first result
     * @param int $sameDayIndex earlier solo results the same day
     */
    public function __construct(
        public InsightsSoloResult $result,
        public null|float $ratio,
        public null|float $form,
        public null|float $residual,
        public string $solveDate,
        public int $solveMoment,
        public int $weekday,
        public null|InsightsTimeOfDay $timeOfDay,
        public null|int $daysSincePrevious,
        public int $sameDayIndex,
    ) {
    }

    /**
     * @return list<InsightsSituation>
     */
    public function situations(): array
    {
        $situations = [$this->result->firstAttempt ? InsightsSituation::FirstTries : InsightsSituation::Repeats];

        if ($this->result->atEvent) {
            $situations[] = InsightsSituation::AtEvent;
        }

        if ($this->weekday >= 6) {
            $situations[] = InsightsSituation::Weekend;
        }

        if ($this->daysSincePrevious !== null && $this->daysSincePrevious > InsightsSituation::BREAK_DAYS) {
            $situations[] = InsightsSituation::AfterBreak;
        }

        if ($this->sameDayIndex >= 1) {
            $situations[] = InsightsSituation::SecondOfTheDay;
        }

        if ($this->result->unboxed) {
            $situations[] = InsightsSituation::Unboxed;
        }

        return $situations;
    }

    public function toItem(null|int $expectedSeconds = null): InsightsResultItem
    {
        return new InsightsResultItem(
            timeId: $this->result->timeId,
            puzzleId: $this->result->puzzleId,
            seconds: $this->result->seconds,
            solvedAt: $this->result->solvedAt,
            attempt: $this->result->attempt,
            firstAttempt: $this->result->firstAttempt,
            piecesCount: $this->result->piecesCount,
            manufacturerName: $this->result->manufacturerName,
            puzzleMedianSeconds: $this->result->puzzleMedianSeconds,
            ratio: $this->ratio,
            residual: $this->residual,
            expectedSeconds: $expectedSeconds,
            daysSincePrevious: $this->daysSincePrevious,
            situations: $this->situations(),
            imageTraits: $this->result->imageTraits(),
        );
    }
}
