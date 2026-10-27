<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\ImageEstimates;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\ImageEstimates\ImageEstimateEvaluator;
use SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\Evaluation\RecordPath;
use SpeedPuzzling\Web\Value\ImageEstimateEvaluationRow;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\PiecesGroup;

/**
 * The numbers of the gate record from stored predictions (ImageEstimateEvaluator) - pure.
 */
final class ImageEstimateEvaluatorTest extends TestCase
{
    public function testSpearmanWithTies(): void
    {
        self::assertEqualsWithDelta(1.0, ImageEstimateEvaluator::spearman([1.0, 2.0, 3.0, 4.0], [10.0, 20.0, 30.0, 40.0]), 1e-12);
        self::assertEqualsWithDelta(-1.0, ImageEstimateEvaluator::spearman([1.0, 2.0, 3.0], [3.0, 2.0, 1.0]), 1e-12);
        // Ranks with ties: [1, 2.5, 2.5, 4] vs [1, 2, 3, 4]
        self::assertEqualsWithDelta(0.9486832980505138, ImageEstimateEvaluator::spearman([1.0, 2.0, 2.0, 3.0], [1.0, 2.0, 3.0, 4.0]), 1e-12);
        self::assertNull(ImageEstimateEvaluator::spearman([1.0, 2.0], [1.0, 2.0]), 'Fewer than 3 pairs');
        self::assertNull(ImageEstimateEvaluator::spearman([1.0, 1.0, 1.0], [1.0, 2.0, 3.0]), 'No variation');
    }

    /**
     * Two puzzles of one group: windows and errors per row, difficulty per puzzle from its latest row, the measured
     * difficulty only from 10 indices on, the paired MAE only where all three predicted.
     */
    public function testRowAndPuzzleMetricsOfAGroup(): void
    {
        $rows = [
            // Puzzle A, measured (12 indices, score 1.2 = Challenging), two rows - the later estimate counts
            $this->row('a', k: 0, actual: 1000, image: 1000, imageLog: 0.0, computedAt: 1.0, blend: 1000, statistical: 1100, measuredIndices: 12, measuredScore: 1.2),
            $this->row('a', k: 2, actual: 1300, image: 1000, imageLog: log(1.2), computedAt: 2.0, blend: 1200, statistical: null, measuredIndices: 12, measuredScore: 1.2),
            // Puzzle B, only 6 indices: no truth for the difficulty numbers
            $this->row('b', k: 4, actual: 900, image: 1000, imageLog: 0.0, computedAt: 1.0, blend: 950, statistical: 1000, measuredIndices: 6, measuredScore: 1.0),
        ];

        $result = ImageEstimateEvaluator::evaluate($rows);

        self::assertCount(1, $result['groups']);
        $group = $result['groups'][0];

        self::assertSame('pieces_500', $group['sizeGroup']);
        self::assertSame('flat', $group['imageKind']);
        self::assertSame(3, $group['rows']);
        self::assertSame(2, $group['puzzles']);
        self::assertSame(1, $group['measuredPuzzles']);
        self::assertNull($group['spearman'], 'One measured puzzle');
        self::assertSame(1.0, $group['tierExact'], 'The latest row estimates 1.2 = Challenging, like the measured');
        self::assertSame(1.0, $group['tierWithinOne']);
        self::assertSame(0.0, $group['alwaysAverage']);

        // Paired = rows a/1 and b: image errors 0 and |ln 0.9|, blend 0 and |ln(900/950)|, statistical |ln(1000/1100)| and |ln 0.9|
        self::assertSame(2, $group['pairedRows']);
        self::assertEqualsWithDelta(round(abs(log(0.9)) / 2, 4), $group['maeImage'], 1e-9);
        self::assertEqualsWithDelta(round(abs(log(900 / 950)) / 2, 4), $group['maeBlend'], 1e-9);
        self::assertEqualsWithDelta(round((abs(log(1000 / 1100)) + abs(log(0.9))) / 2, 4), $group['maeStatistical'], 1e-9);
        self::assertEqualsWithDelta(round((abs(log(1.3)) + abs(log(0.9))) / 3, 4), $group['maeImageAll'], 1e-9);

        // Windows of the image predictor: 50 % = 900-1100, 80 % = 800-1250 for every row
        self::assertEqualsWithDelta(round(2 / 3, 4), $group['window50Hit'], 1e-9, '1000 and 900 inside, 1300 not');
        self::assertEqualsWithDelta(round(2 / 3, 4), $group['window80Hit'], 1e-9);
        self::assertSame(0.2, $group['window50Width']);
        self::assertSame(0.45, $group['window80Width']);

        self::assertSame(3, $result['overall']['rows']);
        self::assertSame(['0', '1-2', '3-4'], array_column($result['byK'], 'k'));
    }

    public function testAlwaysAverageAndTiersOnManyPuzzles(): void
    {
        $rows = [];

        // Ten measured puzzles: the estimate one tier off for three (Average vs Easy), right for seven; four are Average
        foreach (range(1, 10) as $i) {
            $measuredScore = $i <= 4 ? 1.0 : 1.3;
            $estimatedScore = $i <= 3 ? 0.85 : $measuredScore;
            $rows[] = $this->row('p' . $i, k: 0, actual: 1000, image: 1000, imageLog: log($estimatedScore), computedAt: 1.0, blend: null, statistical: null, measuredIndices: 20, measuredScore: $measuredScore);
        }

        $group = ImageEstimateEvaluator::evaluate($rows)['groups'][0];

        self::assertSame(10, $group['measuredPuzzles']);
        self::assertSame(0.7, $group['tierExact']);
        self::assertSame(1.0, $group['tierWithinOne']);
        self::assertSame(0.4, $group['alwaysAverage']);
        self::assertSame(0, $group['pairedRows']);
        self::assertNull($group['maeImage']);
        self::assertNotNull($group['spearman']);
    }

    /**
     * A puzzle whose picture was reclassified between two rows counts at puzzle level in its latest row's kind only;
     * the rows stay where they were made. Every group says which estimate generations its rows rested on.
     */
    public function testAPuzzleCountsInOneKindAndTheEstimateGenerationsAreCounted(): void
    {
        $rows = [
            $this->row('a', k: 0, actual: 1000, image: 1000, imageLog: 0.0, computedAt: 1.0, blend: null, statistical: null, measuredIndices: 12, measuredScore: 1.0),
            $this->row('a', k: 1, actual: 1000, image: 1000, imageLog: 0.0, computedAt: 2.0, blend: null, statistical: null, measuredIndices: 12, measuredScore: 1.0, kind: ImageKind::Box, estimateModel: 'ridge-siglip2-dinov2 1.1.0'),
        ];

        $result = ImageEstimateEvaluator::evaluate($rows);
        $groups = array_column($result['groups'], null, 'imageKind');

        self::assertSame(1, $groups['flat']['rows']);
        self::assertSame(0, $groups['flat']['measuredPuzzles'], 'Its latest row is a box');
        self::assertSame(1, $groups['box']['measuredPuzzles']);
        self::assertSame(1, $result['overall']['measuredPuzzles']);
        self::assertSame(['ridge-siglip2-dinov2 1.0.0' => 1], $groups['flat']['estimateModels']);
        self::assertSame(['ridge-siglip2-dinov2 1.0.0' => 1, 'ridge-siglip2-dinov2 1.1.0' => 1], $result['overall']['estimateModels']);

        $byK = array_column($result['byK'], null, 'k');
        self::assertSame(0, $byK['0']['measuredPuzzles']);
        self::assertSame(1, $byK['1-2']['measuredPuzzles']);
    }

    /**
     * Gate v2 numbers: at k = 0 the picture against the statistical (brand prior) row, from 1 index on the blend; the
     * k = 0 rows of every puzzle and of the measured ones apart; live and reconstructed apart; the tier numbers on the
     * measured puzzles; the honesty split at the median solve unless given.
     */
    public function testGateV2NumbersOfAGroup(): void
    {
        $rows = [
            // Puzzle a, measured: k = 0 picture 1000 vs statistical 1200 (actual 1000), live
            $this->row('a', k: 0, actual: 1000, image: 1000, imageLog: 0.0, computedAt: 1.0, blend: 1000, statistical: 1200, measuredIndices: 12, measuredScore: 1.0, solvedAt: 10.0, live: true),
            // Puzzle b, not measured: k = 0 picture 1100 vs statistical 1000 (actual 1000)
            $this->row('b', k: 0, actual: 1000, image: 1100, imageLog: 0.1, computedAt: 1.0, blend: 1100, statistical: 1000, measuredIndices: 3, measuredScore: null, solvedAt: 20.0),
            // Puzzle a at k = 2: blend 1050 vs statistical 1000 (actual 1000) - the image row's own number is not the candidate here
            $this->row('a', k: 2, actual: 1000, image: 1300, imageLog: 0.0, computedAt: 2.0, blend: 1050, statistical: 1000, measuredIndices: 12, measuredScore: 1.0, solvedAt: 30.0),
        ];

        $result = ImageEstimateEvaluator::evaluate($rows);
        $group = $result['groups'][0];
        $k0 = RecordPath::skill($group, '0');
        $k12 = RecordPath::skill($group, '1-2');

        self::assertSame(20.0, $result['splitAt'], 'The median solve moment');
        self::assertSame(['0', '1-2'], array_column(RecordPath::array($group, 'skill'), 'k'));
        self::assertSame(2, $k0['rows']);
        self::assertEqualsWithDelta(round(log(1.1) / 2, 4), $k0['maeCandidate'], 1e-9);
        self::assertEqualsWithDelta(round(log(1.2) / 2, 4), $k0['maeReference'], 1e-9);
        self::assertArrayHasKey('width80Candidate', $k0);
        self::assertEqualsWithDelta(round(log(1.05), 4), $k12['maeCandidate'], 1e-9, 'The blend, not the image row');
        self::assertSame(0.0, $k12['maeReference']);

        self::assertSame(2, RecordPath::at($group, 'slices', 'k0', 'rows'), 'Every newer puzzle at k = 0');
        self::assertSame(1, RecordPath::at($group, 'slices', 'k0Measured', 'rows'), 'Only the measured one');
        self::assertSame(1, RecordPath::at($group, 'slices', 'live', 'rows'));
        self::assertSame(2, RecordPath::at($group, 'slices', 'reconstructed', 'rows'));

        // The honesty numbers rest on the picture-only windows of every row; one row before the split
        self::assertSame(1, RecordPath::at($group, 'calibration', 'fitRows'));
        self::assertSame(2, RecordPath::at($group, 'calibration', 'testRows'));
        self::assertFalse(RecordPath::at($group, 'calibration', 'previouslyOpen'));

        // Tier numbers on the one measured puzzle (its latest estimate 0.0 vs measured 1.0: Average both)
        self::assertSame(1.0, $group['tierExact']);
        self::assertSame(1.0, $group['tierRangeCoverage']);
        self::assertNotNull($group['tierExactCeiling']);

        self::assertArrayHasKey('calibration', $result['overall']);
        self::assertSame(20.0, ImageEstimateEvaluator::evaluate($rows, splitAt: 20.0)['splitAt']);
    }

    /**
     * The hysteresis carries over only for the estimate generations the previous record measured in the group.
     */
    public function testHysteresisOnlyForTheSameEstimateGenerations(): void
    {
        $rows = [$this->row('a', k: 0, actual: 1000, image: 1000, imageLog: 0.0, computedAt: 1.0, blend: 1000, statistical: 1000, measuredIndices: 1, measuredScore: null)];

        $same = ImageEstimateEvaluator::evaluate($rows, openBefore: ['pieces_500|flat' => ['ridge-siglip2-dinov2 1.0.0']]);
        self::assertTrue(RecordPath::at($same['groups'], 0, 'calibration', 'previouslyOpen'));

        $other = ImageEstimateEvaluator::evaluate($rows, openBefore: ['pieces_500|flat' => ['ridge-siglip2-dinov2 1.1.0']]);
        self::assertFalse(RecordPath::at($other['groups'], 0, 'calibration', 'previouslyOpen'), 'A new estimate generation earns the opening again');

        $elsewhere = ImageEstimateEvaluator::evaluate($rows, openBefore: ['pieces_500|box' => ['ridge-siglip2-dinov2 1.0.0']]);
        self::assertFalse(RecordPath::at($elsewhere['groups'], 0, 'calibration', 'previouslyOpen'));
    }

    private function row(
        string $puzzle,
        int $k,
        int $actual,
        int $image,
        float $imageLog,
        float $computedAt,
        null|int $blend,
        null|int $statistical,
        int $measuredIndices,
        null|float $measuredScore,
        ImageKind $kind = ImageKind::Flat,
        string $estimateModel = 'ridge-siglip2-dinov2 1.0.0',
        float $solvedAt = 0.0,
        bool $live = false,
    ): ImageEstimateEvaluationRow {
        return new ImageEstimateEvaluationRow(
            puzzleId: $puzzle,
            sizeGroup: PiecesGroup::FiveHundred,
            imageKind: $kind,
            kIndices: $k,
            actualSeconds: $actual,
            imageLogDifficulty: $imageLog,
            computedAt: $computedAt,
            imageSeconds: $image,
            imageRange50Low: 900,
            imageRange50High: 1100,
            imageRange80Low: 800,
            imageRange80High: 1250,
            blendSeconds: $blend,
            blendRange50Low: $blend !== null ? $blend - 50 : null,
            blendRange50High: $blend !== null ? $blend + 50 : null,
            blendRange80Low: $blend !== null ? $blend - 100 : null,
            blendRange80High: $blend !== null ? $blend + 100 : null,
            statisticalSeconds: $statistical,
            measuredIndices: $measuredIndices,
            measuredScore: $measuredScore,
            estimateModel: $estimateModel,
            solvedAt: $solvedAt,
            live: $live,
            statisticalRange80Low: $statistical !== null ? $statistical - 300 : null,
            statisticalRange50Low: $statistical !== null ? $statistical - 150 : null,
            statisticalRange50High: $statistical !== null ? $statistical + 150 : null,
            statisticalRange80High: $statistical !== null ? $statistical + 300 : null,
            estimateP10Offset: -0.2,
            estimateP90Offset: 0.25,
        );
    }
}
