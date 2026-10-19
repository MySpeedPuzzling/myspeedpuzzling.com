<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Results\CloseCallCandidate;
use SpeedPuzzling\Web\Results\Insights\InsightsReport;
use SpeedPuzzling\Web\Results\InsightsContext;
use SpeedPuzzling\Web\Results\InsightsSoloResult;

/**
 * Builds the core sections of one player's Insights page from the loaded rows - pure PHP, no query inside
 * (docs/features/insights/computation.md "Core sections"). The rows are prepared once over all results (ratio, your
 * usual, residual, solve day), then filtered:
 * - the selection = every filter incl. the period: summary, skill map, histogram + outliers, expected vs actual,
 *   habits, consistency, prediction accuracy, findings;
 * - the selection without its period: the trend tile, and the form chart (which keeps the period's own results and
 *   starts at your first result);
 * - the puzzle filters only, all time: close calls and the learning curve (about puzzles, not about when);
 * - nothing: the profile card (all your puzzling, batch-computed).
 */
readonly final class InsightsBuilder
{
    public const int DEFAULT_COMMUNITY_PIECES = 500;

    public function __construct(
        private InsightsRowsPreparer $preparer,
        private SummaryBuilder $summaryBuilder,
        private FormBuilder $formBuilder,
        private SkillMapBuilder $skillMapBuilder,
        private HistogramBuilder $histogramBuilder,
        private ExpectedVsActualBuilder $expectedBuilder,
        private CloseCallsBuilder $closeCallsBuilder,
        private LearningCurveBuilder $learningCurveBuilder,
        private HabitsBuilder $habitsBuilder,
        private ConsistencyBuilder $consistencyBuilder,
        private PredictionAccuracyBuilder $accuracyBuilder,
        private ProfileCardBuilder $profileCardBuilder,
        private FindingsBuilder $findingsBuilder,
        private NudgesBuilder $nudgesBuilder,
    ) {
    }

    /**
     * @param list<InsightsSoloResult> $results GetInsightsSoloResults::forPlayer(), oldest first
     * @param array<string, CloseCallCandidate> $closeCalls GetCloseCalls::forPlayer()
     * @param null|DateTimeZone $timezone the player's own zone (player.timezone), null = none
     * @param bool $predictionsAllowed false for a player who switched time predictions off (player.time_predictions_opted_out):
     *                                 no expected times anywhere, like the puzzle page, the Picker and the recap
     */
    public function build(
        string $playerId,
        array $results,
        array $closeCalls,
        InsightsContext $context,
        InsightsCriteria $criteria,
        null|DateTimeZone $timezone,
        DateTimeImmutable $now,
        bool $predictionsAllowed = true,
    ): InsightsReport {
        $learningFactors = LearningFactors::fromGlobalRatios($context->globalImprovementRatios);
        $rows = $this->preparer->prepare($results, $learningFactors, $timezone);

        $withoutPeriod = [];
        $selection = [];
        $puzzleRows = [];
        $puzzles = [];

        foreach ($rows as $row) {
            $puzzles[$row->result->puzzleId] = $row->result;

            if ($criteria->matchesPuzzle($row->result)) {
                $puzzleRows[] = $row;
            }

            if ($criteria->matchesResult($row->result, $row->weekday, $row->timeOfDay)) {
                $withoutPeriod[] = $row;

                if ($criteria->inPeriod($row->result->solvedAt, $now)) {
                    $selection[] = $row;
                }
            }
        }

        $summary = $this->summaryBuilder->build($selection, $withoutPeriod, $now);
        $form = $this->formBuilder->build($withoutPeriod, $criteria->periodFrom($now), $criteria->periodBefore(), $now);
        $skillMap = $this->skillMapBuilder->build($selection);
        $histogram = $this->histogramBuilder->build($selection);
        $expected = $this->expectedBuilder->build($predictionsAllowed ? $selection : []);
        $closeCallsSection = $this->closeCallsBuilder->build($closeCalls, $puzzles, $criteria);
        $learning = $this->learningCurveBuilder->build(
            $puzzleRows,
            $context->playerImprovementRatios,
            $learningFactors,
            $criteria->singlePiecesClass() ?? self::DEFAULT_COMMUNITY_PIECES,
        );
        $habits = $this->habitsBuilder->build($selection, $timezone !== null);
        $consistency = $this->consistencyBuilder->build($selection, $context->profileAxes, $now);
        $accuracy = $this->accuracyBuilder->build($predictionsAllowed ? $selection : []);
        $profileCard = $this->profileCardBuilder->build($context);
        $findings = $this->findingsBuilder->build($selection, $summary, $skillMap, $closeCallsSection, $expected);

        // The puzzles the sections list, most prominent first - the hydrate statement reads these
        $shown = [];

        foreach ($findings as $finding) {
            $shown[] = $finding->puzzleId;
        }

        foreach ($closeCallsSection->items as $item) {
            $shown[] = $item->puzzleId;
        }

        foreach ([...$histogram->slowOutliers, ...$histogram->fastOutliers, ...$expected->better, ...$expected->worse] as $item) {
            $shown[] = $item->puzzleId;
        }

        foreach ($learning->learnedMost as $learned) {
            $shown[] = $learned->puzzleId;
        }

        foreach (array_reverse($form->months) as $month) {
            $shown[] = $month->bestPuzzleId;
        }

        $shown = array_slice(array_values(array_unique(array_filter($shown, is_string(...)))), 0, InsightsReport::MAX_SHOWN_PUZZLES);

        return new InsightsReport(
            playerId: $playerId,
            isMember: $criteria->isMember,
            criteria: $criteria->toQueryParameters(),
            builtAt: $now,
            generation: $context->generation,
            hasTimezone: $timezone !== null,
            summary: $summary,
            findings: $findings,
            profileCard: $profileCard,
            form: $form,
            skillMap: $skillMap,
            expectedVsActual: $expected,
            histogram: $histogram,
            closeCalls: $closeCallsSection,
            learningCurve: $learning,
            habits: $habits,
            consistency: $consistency,
            predictionAccuracy: $accuracy,
            puzzleIdsShown: $shown,
            totalResultCount: count($rows),
            predictionsOff: $predictionsAllowed === false,
            nudges: $this->nudgesBuilder->nudges($rows, $timezone !== null, $predictionsAllowed),
            filterOptions: $this->nudgesBuilder->filterOptions($rows),
        );
    }
}
