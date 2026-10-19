<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\CloseCallCandidate;
use SpeedPuzzling\Web\Results\Insights\InsightsReport;
use SpeedPuzzling\Web\Results\InsightsContext;
use SpeedPuzzling\Web\Results\InsightsSoloResult;
use SpeedPuzzling\Web\Services\Insights\CloseCallsBuilder;
use SpeedPuzzling\Web\Services\Insights\ConsistencyBuilder;
use SpeedPuzzling\Web\Services\Insights\ExpectedVsActualBuilder;
use SpeedPuzzling\Web\Services\Insights\FindingsBuilder;
use SpeedPuzzling\Web\Services\Insights\FormBuilder;
use SpeedPuzzling\Web\Services\Insights\HabitsBuilder;
use SpeedPuzzling\Web\Services\Insights\HistogramBuilder;
use SpeedPuzzling\Web\Services\Insights\InsightsBuilder;
use SpeedPuzzling\Web\Services\Insights\InsightsCriteria;
use SpeedPuzzling\Web\Services\Insights\InsightsRowsPreparer;
use SpeedPuzzling\Web\Services\Insights\LearningCurveBuilder;
use SpeedPuzzling\Web\Services\Insights\NudgesBuilder;
use SpeedPuzzling\Web\Services\Insights\PredictionAccuracyBuilder;
use SpeedPuzzling\Web\Services\Insights\ProfileCardBuilder;
use SpeedPuzzling\Web\Services\Insights\SkillMapBuilder;
use SpeedPuzzling\Web\Services\Insights\SummaryBuilder;
use SpeedPuzzling\Web\Value\InsightsFindingCode;
use SpeedPuzzling\Web\Value\InsightsSection;
use SpeedPuzzling\Web\Value\TimePredictionMethod;

final class InsightsBuilderTest extends TestCase
{
    private const string PLAYER = '00000000-0000-7000-8000-ffffffffffff';

    public static function builder(): InsightsBuilder
    {
        return new InsightsBuilder(
            new InsightsRowsPreparer(),
            new SummaryBuilder(),
            new FormBuilder(),
            new SkillMapBuilder(),
            new HistogramBuilder(),
            new ExpectedVsActualBuilder(),
            new CloseCallsBuilder(),
            new LearningCurveBuilder(),
            new HabitsBuilder(),
            new ConsistencyBuilder(),
            new PredictionAccuracyBuilder(),
            new ProfileCardBuilder(),
            new FindingsBuilder(),
            new NudgesBuilder(),
        );
    }

    public function testTheFindingsNameRealPuzzlesAndTheShownPuzzlesAreHydratable(): void
    {
        $results = self::player(400);
        $closeCalls = [];

        foreach (array_slice($results, 0, 30) as $index => $result) {
            $closeCalls[$result->puzzleId] = new CloseCallCandidate($result->puzzleId, $result->seconds, 5 + $index, $result->seconds - 10, [1 => 1000, 3 => $result->seconds - 30 - $index]);
        }

        $report = self::builder()->build(
            self::PLAYER,
            $results,
            $closeCalls,
            InsightsContext::empty(),
            InsightsCriteria::fromUserInput(['period' => 'all'], isMember: true),
            null,
            InsightsTestResults::now(),
        );

        $codes = array_map(static fn ($finding) => $finding->code, $report->findings);
        self::assertContains(InsightsFindingCode::Speed, $codes);
        self::assertContains(InsightsFindingCode::Strength, $codes);
        self::assertContains(InsightsFindingCode::CloseCall, $codes);
        self::assertContains(InsightsFindingCode::BestResult, $codes);

        foreach ($report->findings as $finding) {
            self::assertSame($finding->code === InsightsFindingCode::Surprises, $finding->membersOnly);

            if ($finding->code === InsightsFindingCode::CloseCall) {
                self::assertSame(InsightsSection::CloseCalls, $finding->section);
                self::assertSame(3, $finding->rank);
                self::assertContains($finding->puzzleId, $report->puzzleIdsShown);
            }
        }

        self::assertLessThanOrEqual(InsightsReport::MAX_SHOWN_PUZZLES, count($report->puzzleIdsShown));
        self::assertSame(array_values(array_unique($report->puzzleIdsShown)), $report->puzzleIdsShown);

        foreach ($report->closeCalls->items as $item) {
            self::assertContains($item->puzzleId, $report->puzzleIdsShown);
        }

        self::assertSame(400, $report->summary->resultCount);
        self::assertFalse($report->skillMap->notSureYet);
        self::assertSame(30, $report->closeCalls->puzzleCount);

        // The report goes into the page cache: it must survive serialization unchanged
        self::assertEquals($report, unserialize(serialize($report)));
    }

    public function testFreePlayersSeeSixMonthsWhateverTheyAskFor(): void
    {
        $report = self::builder()->build(
            self::PLAYER,
            self::player(400),
            [],
            InsightsContext::empty(),
            InsightsCriteria::fromUserInput(['period' => 'all', 'results' => 'first'], isMember: false),
            null,
            InsightsTestResults::now(),
        );

        self::assertFalse($report->isMember);
        self::assertSame(['period', 'results'], $report->strippedForMembers);
        self::assertSame([], $report->criteria);
        self::assertLessThan(400, $report->summary->resultCount);
        self::assertLessThanOrEqual(7, count($report->form->months));
    }

    public function testAPlayerWithoutResultsGetsNotSureYetEverywhere(): void
    {
        $report = self::builder()->build(self::PLAYER, [], [], InsightsContext::empty(), InsightsCriteria::fromUserInput([], isMember: true), null, InsightsTestResults::now());

        self::assertSame([], $report->findings);
        self::assertTrue($report->form->notSureYet);
        self::assertTrue($report->skillMap->notSureYet);
        self::assertTrue($report->histogram->notSureYet);
        self::assertTrue($report->expectedVsActual->notSureYet);
        self::assertTrue($report->closeCalls->notSureYet);
        self::assertTrue($report->learningCurve->notSureYet);
        self::assertTrue($report->habits->notSureYet);
        self::assertTrue($report->consistency->notSureYet);
        self::assertTrue($report->predictionAccuracy->notSureYet);
        self::assertTrue($report->profileCard->notSureYet);
        self::assertSame([], $report->puzzleIdsShown);
    }

    /**
     * Performance guard: the heaviest production player has ~5k solo results - the build is linear-ish (sorted
     * windows, order statistics), measured 8-14 ms on the production copy. A quadratic slip (a window sorted per
     * result, a bootstrap) takes seconds here.
     */
    public function testBuildingTheHeaviestPlayerStaysFast(): void
    {
        $results = self::player(6000);
        $closeCalls = [];

        foreach ($results as $index => $result) {
            if ($index % 3 === 0) {
                $closeCalls[$result->puzzleId] = new CloseCallCandidate($result->puzzleId, $result->seconds, 40, $result->seconds - 5, [1 => 1000, 3 => 1100, 10 => 1200, 25 => 1300]);
            }
        }

        $builder = self::builder();
        $criteria = InsightsCriteria::fromUserInput(['period' => 'all'], isMember: true);
        $started = hrtime(true);
        $report = $builder->build(self::PLAYER, $results, $closeCalls, InsightsContext::empty(), $criteria, new \DateTimeZone('Europe/Prague'), InsightsTestResults::now());
        $milliseconds = (hrtime(true) - $started) / 1e6;

        self::assertSame(6000, $report->summary->resultCount);
        self::assertLessThan(1500, $milliseconds, sprintf('Building 6,000 results took %.0f ms', $milliseconds));
    }

    /**
     * A plausible player: four years of results, several brands and sizes, repeats, events, predictions.
     *
     * @return list<InsightsSoloResult>
     */
    public static function player(int $count): array
    {
        mt_srand(7);
        $results = [];
        $brands = ['00000000-0000-7000-a000-000000000001', '00000000-0000-7000-a000-000000000002', '00000000-0000-7000-a000-000000000003'];

        for ($i = 0; $i < $count; $i++) {
            $daysAgo = (int) round(1400 - $i * 1400 / $count);
            $brand = $brands[$i % 3];
            $pieces = $i % 10 === 0 ? 1000 : 500;
            $median = $pieces === 1000 ? 7200 : 3000;
            // Brand 2 is clearly faster; everybody gets faster over time
            $ratio = (0.85 + 0.25 * $daysAgo / 1400) * ($brand === $brands[1] ? 0.88 : 1.0) * exp((mt_rand(0, 1000) / 1000 - 0.5) * 0.2);
            $seconds = (int) round($median * $ratio);
            $repeat = $i % 7 === 0;

            $results[] = InsightsTestResults::result([
                'seconds' => $seconds,
                'days_ago' => $daysAgo,
                'firstAttempt' => $repeat === false,
                'attempt' => $repeat ? 2 : 1,
                'atEvent' => $i % 25 === 0,
                'piecesCount' => $pieces,
                'puzzleMedianSeconds' => $median,
                'manufacturerId' => $brand,
                'manufacturerName' => 'Brand ' . ($i % 3 + 1),
                'difficultyTier' => $i % 6 + 1,
                'predictable' => true,
                'predictionMethod' => $repeat ? TimePredictionMethod::Personal : TimePredictionMethod::Statistical,
                'predictedSeconds' => (int) round($median * 1.05),
                'predictedRangeLowSeconds' => (int) round($median * 0.95),
                'predictedRangeHighSeconds' => (int) round($median * 1.15),
                'predictionModelVersion' => $i % 2 === 0 ? 3 : 2,
            ]);
        }

        return $results;
    }
}
