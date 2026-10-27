<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\ImageEstimates;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\ImageEstimateEvaluationResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\ImageEstimates\ImageEstimateGate;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Tests\TestClock;

/**
 * Gate v2's rules on one group's numbers (ImageEstimateGate::failures()) - honesty out of period with hysteresis, skill
 * against the brand prior and the statistical prediction, ranking - every rule fails on its own, a missing number
 * fails, and the exact tier never gates.
 */
final class ImageEstimateGateRulesTest extends TestCase
{
    private const string GENERATION = 'ridge-siglip2-dinov2 1.1.0';

    public function testAGroupMeetingEveryGatePasses(): void
    {
        self::assertSame([], ImageEstimateGate::failures(self::passing()));
    }

    public function testTheExactTierIsReportedButNeverGates(): void
    {
        self::assertSame([], ImageEstimateGate::failures(self::passing(['tierExact' => 0.2, 'tierWithinOne' => 0.5, 'alwaysAverage' => 0.9])));
    }

    /**
     * @param array<string, mixed> $change
     */
    #[DataProvider('failingGroups')]
    public function testEveryGateFailsOnItsOwn(array $change, string $failure): void
    {
        $failures = ImageEstimateGate::failures(self::passing($change));

        self::assertCount(1, $failures, implode(' | ', $failures));
        self::assertStringContainsString($failure, $failures[0]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function failingGroups(): iterable
    {
        yield 'ranking: too few measured puzzles' => [['measuredPuzzles' => 49], 'measured puzzles'];
        yield 'ranking: rank correlation' => [['spearman' => 0.59], 'Spearman'];
        yield 'ranking: no rank correlation' => [['spearman' => null], 'Spearman'];

        yield 'honesty: 50 % Wilson interval reaches below 47 %' => [['calibration' => self::calibration(['hit50' => 0.49, 'hit50Low' => 0.469, 'hit50High' => 0.511])], '50 % window'];
        yield 'honesty: 80 % Wilson interval reaches above 83 %' => [['calibration' => self::calibration(['hit80' => 0.81, 'hit80Low' => 0.79, 'hit80High' => 0.831])], '80 % window'];
        yield 'honesty: a point inside ± 3 with too few solves to show it' => [['calibration' => self::calibration(['hit50' => 0.5, 'hit50Low' => 0.44, 'hit50High' => 0.56])], '50 % window'];
        yield 'honesty: open before, the share left ± 5' => [['calibration' => self::calibration(['previouslyOpen' => true, 'hit80' => 0.851, 'hit80Low' => 0.84, 'hit80High' => 0.86])], 'hysteresis'];
        yield 'honesty: no numbers' => [['calibration' => self::calibration(['hit50Low' => null])], 'no out-of-period 50 %'];

        yield 'skill: the picture not 10 % better than the brand prior at k = 0' => [['skill' => self::skill('0', ['maeCandidate' => 0.172, 'maeReference' => 0.19])], 'k = 0 error'];
        yield 'skill: a window no sharper than the brand prior\'s at equal coverage' => [['skill' => self::skill('0', ['width80Candidate' => 0.57])], 'equal coverage'];
        yield 'skill: too few solves at k = 0' => [['skill' => self::skill('0', ['rows' => 29])], 'paired solves at k = 0'];
        yield 'skill: the blend measurably worse at k = 1-2' => [['skill' => self::skill('1-2', ['meanDifference' => 0.004, 'differenceSe' => 0.002])], 'k = 1-2 blend'];
        yield 'skill: the blend measurably worse at k = 3-4' => [['skill' => self::skill('3-4', ['meanDifference' => 0.002, 'differenceSe' => 0.001])], 'k = 3-4 blend'];
        yield 'skill: too few solves at k = 1-4' => [['skill' => [self::skill('0', [])[0], ['k' => '1-2', 'rows' => 20, 'meanDifference' => 0.05, 'differenceSe' => 0.001]]], 'paired solves at k = 1-4'];
    }

    public function testHysteresisKeepsAGroupOpenWithinFivePoints(): void
    {
        // Not open before: a Wilson interval reaching outside ± 3 closes it
        $drifted = ['hit80' => 0.835, 'hit80Low' => 0.82, 'hit80High' => 0.85];
        self::assertNotSame([], ImageEstimateGate::failures(self::passing(['calibration' => self::calibration($drifted)])));
        // Open before: the share within ± 5 keeps it open
        self::assertSame([], ImageEstimateGate::failures(self::passing(['calibration' => self::calibration(['previouslyOpen' => true] + $drifted)])));
    }

    public function testTheBlendWithinItsNoiseIsNotWorse(): void
    {
        // +0.0008 with a standard error of 0.0012: not measurably worse (the point alone would flip on noise)
        self::assertSame([], ImageEstimateGate::failures(self::passing(['skill' => self::skill('3-4', ['meanDifference' => 0.0008, 'differenceSe' => 0.0012])])));
        // A bucket too small to judge is left out as long as k = 1-4 together has enough solves
        self::assertSame([], ImageEstimateGate::failures(self::passing(['skill' => self::skill('3-4', ['rows' => 7, 'meanDifference' => 0.05, 'differenceSe' => 0.01])])));
    }

    public function testAnEmptyGroupFailsEveryRule(): void
    {
        $failures = ImageEstimateGate::failures([]);

        self::assertCount(5, $failures, implode(' | ', $failures));
    }

    /**
     * The hysteresis a record carries to the next: groups whose honesty its numbers opened, with their generations -
     * only from a gate v2 record of the current model version.
     */
    public function testHonestyOpenGroupsOfARecord(): void
    {
        $open = GateV2Numbers::passing('pieces_500', 'flat', self::GENERATION);
        $closed = ['calibration' => self::calibration(['hit50Low' => 0.40])] + GateV2Numbers::passing('pieces_500', 'box', self::GENERATION);
        $record = static fn (int $gateVersion, int $modelVersion): ImageEstimateEvaluationResult => new ImageEstimateEvaluationResult('id', TestClock::now(), $modelVersion, null, 10, [$open, $closed], [], [], $gateVersion);

        self::assertSame(['pieces_500|flat' => [self::GENERATION]], ImageEstimateGate::honestyOpenGroups($record(2, TimePredictionCalculator::MODEL_VERSION)));
        self::assertSame([], ImageEstimateGate::honestyOpenGroups($record(1, TimePredictionCalculator::MODEL_VERSION)), 'A gate v1 record');
        self::assertSame([], ImageEstimateGate::honestyOpenGroups($record(2, TimePredictionCalculator::MODEL_VERSION + 1)), 'Another model version');
        self::assertSame([], ImageEstimateGate::honestyOpenGroups(null));

        // Judged by another refit (a record from before the picture-only window's own refit): earns the opening again
        $equalWeight = ['calibration' => array_diff_key(self::calibration([]), array_flip(['refitHalfLifeDays', 'refitByFirstSolver', 'refitMinimumEffective']))] + $open;
        $olderRecord = new ImageEstimateEvaluationResult('id', TestClock::now(), TimePredictionCalculator::MODEL_VERSION, null, 10, [$equalWeight], [], [], 2);
        self::assertSame([], ImageEstimateGate::honestyOpenGroups($olderRecord));
        self::assertSame([], ImageEstimateGate::failures(['calibration' => $equalWeight['calibration']] + $open), 'Its own verdict does not depend on the refit');
    }

    /**
     * @param array<string, mixed> $change
     * @return array<string, mixed>
     */
    private static function passing(array $change = []): array
    {
        return [...GateV2Numbers::passing('pieces_500', 'flat', self::GENERATION), ...$change];
    }

    /**
     * @param array<string, mixed> $change
     * @return array<string, mixed>
     */
    private static function calibration(array $change): array
    {
        return GateV2Numbers::calibration($change);
    }

    /**
     * @param array<string, mixed> $change
     * @return list<array<string, mixed>>
     */
    private static function skill(string $bucket, array $change): array
    {
        return GateV2Numbers::skill($bucket, $change);
    }
}
