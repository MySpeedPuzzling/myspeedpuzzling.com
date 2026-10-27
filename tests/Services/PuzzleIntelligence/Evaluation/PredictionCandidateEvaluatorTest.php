<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\Evaluation;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\PredictionCandidateEvaluator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\PredictionGroupAccumulator;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictionPairRow;
use SpeedPuzzling\Web\Value\PredictorKind;
use SpeedPuzzling\Web\Value\StoredPredictionWindows;

/**
 * Gate v2 for a candidate time model (PredictionCandidateEvaluator) on synthetic side-by-side rows: the same honesty and
 * skill rules as the picture estimates, whatever predictor and model version made the rows - pure.
 */
final class PredictionCandidateEvaluatorTest extends TestCase
{
    private const int DAY = 86400;

    /** Log offsets of the actual time from the truth: 5 of 10 inside ± 0.1, 8 inside ± 0.2 */
    private const array OFFSETS = [-0.02, 0.02, -0.05, 0.05, 0.08, 0.12, -0.15, 0.18, 0.3, -0.4];

    public function testTheSubjectNamesBothPredictors(): void
    {
        self::assertSame('statistical@4 vs statistical@3', PredictionCandidateEvaluator::subject(PredictorKind::Statistical, 4, PredictorKind::Statistical, 3));
    }

    /**
     * A calibrated candidate centred on the truth against a reference 10 % off: passes in its size group; groups and
     * slices as stored (live vs reconstructed, repeats a bucket of their own).
     */
    public function testABetterCalibratedCandidatePasses(): void
    {
        $result = PredictionCandidateEvaluator::evaluate($this->rows(candidateBias: 0.0, referenceBias: 0.1));

        self::assertCount(1, $result['groups']);
        $group = $result['groups'][0];

        self::assertSame('pieces_500', $group['sizeGroup']);
        self::assertSame(PredictionCandidateEvaluator::ANY_KIND, $group['imageKind']);
        self::assertSame(1800, $group['rows']);
        self::assertSame(90.5 * self::DAY, $result['splitAt'], 'The median solve');
        self::assertSame(['0', '1-2', '20+', 'repeat'], array_column($group['skill'], 'k'));
        self::assertSame(['live', 'reconstructed'], array_keys($group['slices']));
        self::assertFalse($group['calibration']['previouslyOpen']);
        self::assertSame([], PredictionCandidateEvaluator::failures($group));
    }

    public function testACandidateWorseThanTheReferenceFails(): void
    {
        $group = PredictionCandidateEvaluator::evaluate($this->rows(candidateBias: 0.1, referenceBias: 0.0))['groups'][0];
        $failures = PredictionCandidateEvaluator::failures($group);

        self::assertNotSame([], $failures);
        self::assertStringContainsString('k = 0: error above the reference', implode(' | ', $failures));
        self::assertStringContainsString('over all paired solves', implode(' | ', $failures));
    }

    /**
     * Hysteresis: a group open in the previous record keeps that on the next one (the verdict on read).
     */
    public function testHysteresisIsCarriedPerGroup(): void
    {
        $open = PredictionCandidateEvaluator::honestyOpenGroups(PredictionCandidateEvaluator::evaluate($this->rows(0.0, 0.1))['groups']);
        self::assertSame(['pieces_500|any' => []], $open);

        $next = PredictionCandidateEvaluator::evaluate($this->rows(0.0, 0.1), $open)['groups'][0];
        self::assertTrue($next['calibration']['previouslyOpen']);
        self::assertSame([], PredictionCandidateEvaluator::honestyOpenGroups([]));
    }

    /**
     * A bucket worse within the non-inferiority margin passes, however significant; beyond it, it fails.
     */
    public function testTheNonInferiorityMargin(): void
    {
        $group = PredictionCandidateEvaluator::evaluate($this->rows(0.0, 0.1))['groups'][0];
        $bucket = static fn (float $difference): array => ['k' => '10-19', 'rows' => 900, 'maeCandidate' => 0.0888, 'maeReference' => 0.0888, 'meanDifference' => $difference, 'differenceSe' => 0.0001];

        self::assertSame([], PredictionCandidateEvaluator::failures(['skill' => [...$group['skill'], $bucket(0.0003)]] + $group));

        $failures = PredictionCandidateEvaluator::failures(['skill' => [...$group['skill'], $bucket(0.0025)]] + $group);
        self::assertCount(1, $failures);
        self::assertStringContainsString('k = 10-19', $failures[0]);
    }

    public function testTooFewPairedSolvesFail(): void
    {
        $failures = PredictionCandidateEvaluator::failures(['calibration' => [], 'skill' => [['k' => '0', 'rows' => 10, 'maeCandidate' => 0.1, 'maeReference' => 0.2, 'meanDifference' => -0.1, 'differenceSe' => 0.01]]]);

        self::assertContains('10 paired solves, at least 30 needed', $failures);
        self::assertSame([], PredictionGroupAccumulator::skillBucket('not a list', '0'));
    }

    /**
     * 180 days, 10 solves a day around 1000 s at the OFFSETS; the candidate / reference centred $bias above the truth,
     * windows ± 0.1 / ± 0.2 around their centre. k cycles through 0, 2, 25 and a repeat; every fifth row live.
     *
     * @return list<PredictionPairRow>
     */
    private function rows(float $candidateBias, float $referenceBias): array
    {
        $rows = [];
        $ks = [0, 2, 25, null];

        for ($day = 0; $day < 180; $day++) {
            foreach (self::OFFSETS as $i => $offset) {
                $rows[] = new PredictionPairRow(
                    sizeGroup: PiecesGroup::FiveHundred,
                    kIndices: $ks[($day + $i) % 4],
                    actualSeconds: (int) round(1000 * exp($offset)),
                    solvedAt: ($day + 0.5) * self::DAY,
                    live: $i % 5 === 0,
                    candidate: self::around(1000 * exp($candidateBias)),
                    reference: self::around(1000 * exp($referenceBias)),
                );
            }
        }

        return $rows;
    }

    private static function around(float $centre): StoredPredictionWindows
    {
        return new StoredPredictionWindows(
            (int) round($centre),
            (int) round($centre * exp(-0.2)),
            (int) round($centre * exp(-0.1)),
            (int) round($centre * exp(0.1)),
            (int) round($centre * exp(0.2)),
        );
    }
}
