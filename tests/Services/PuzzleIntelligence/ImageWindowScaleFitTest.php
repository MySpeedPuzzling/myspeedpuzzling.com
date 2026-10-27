<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\FirstAttemptSet;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionEngine;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionModelFitter;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PriorLookup;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\RepeatHistory;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimeline;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\ImagePriorInput;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Component\Clock\MockClock;

/**
 * The picture-only window's scales (PredictionModelFitter step 6): the estimate's own quantiles are the picture's part
 * of a prediction resting on the picture alone, and the daily fit stretches that part - one scale per window, per size
 * group × image kind × k = 0 / 1-4, recency-weighted - until those windows hold what they say.
 */
final class ImageWindowScaleFitTest extends TestCase
{
    private const float DAY = 86400.0;
    private const int BASELINE = 3000;

    /**
     * 3,000 first tries at new puzzles (k = 0) whose picture part scatters twice as much as the estimate's interval
     * says: both windows' scales land near 2 and the fitted windows hold 50 / 80 %.
     */
    public function testTheFitStretchesThePictureOnlyWindowUntilItHoldsWhatItSays(): void
    {
        $parameters = $this->fit([500 => 2.0]);
        $summary = $parameters->summary;
        $all = $parameters->imageWindowScales[PredictionModelParameters::IMAGE_WINDOW_ALL];

        self::assertSame(3000, $summary['image_window_samples']);
        self::assertEqualsWithDelta(2.0, $all[0], 0.5);
        self::assertEqualsWithDelta(2.0, $all[1], 0.5);
        self::assertEqualsWithDelta(0.50, $summary['image_window50_hit'], 0.03);
        self::assertEqualsWithDelta(0.80, $summary['image_window80_hit'], 0.03);
        self::assertSame($all, $parameters->imageWindowScalesFor(PiecesGroup::Thousand, ImageKind::Flat, 0), 'Nothing more specific fitted: all');
        self::assertSame([], $parameters->imageVarianceScales, 'The symmetric form is no longer fitted');
    }

    public function testWithoutEstimatesNoWindowScales(): void
    {
        $parameters = $this->fit([500 => 2.0], withEstimates: false);

        self::assertSame([], $parameters->imageWindowScales);
        self::assertSame(0, $parameters->summary['image_window_samples']);
    }

    /**
     * 300-piece first tries scatter three times the interval, 500-piece ones 1.5 times: each size group (and its kind,
     * and its k bucket) gets its own scales, a group without first tries `all`.
     */
    public function testEachSizeGroupAndKindGetsItsOwnScales(): void
    {
        $parameters = $this->fit([300 => 3.0, 500 => 1.5]);
        $small = $parameters->imageWindowScalesFor(PiecesGroup::UpTo300, ImageKind::Flat, 0);
        $five = $parameters->imageWindowScalesFor(PiecesGroup::FiveHundred, ImageKind::Flat, 0);

        self::assertNotNull($small);
        self::assertNotNull($five);
        self::assertSame($parameters->imageWindowScales['up_to_300|flat|k0'], $small);
        self::assertGreaterThan($five[0] * 1.4, $small[0]);
        self::assertGreaterThan($five[1] * 1.4, $small[1]);
        self::assertSame($parameters->imageWindowScales['up_to_300|k0'], $parameters->imageWindowScalesFor(PiecesGroup::UpTo300, ImageKind::Box, 0), 'Kind without its own fit: the size group × k bucket');
        self::assertSame($parameters->imageWindowScales['all'], $parameters->imageWindowScalesFor(PiecesGroup::Thousand, ImageKind::Flat, 0), 'Group without first tries: all');
        self::assertStringEndsWith('(1500)', (string) $parameters->summary['image_window_up_to_300|flat|k0']);
    }

    /**
     * The first tries of the last weeks weigh more: a third of them long ago with four times the picture's interval, two
     * thirds recent with the interval as is - an equal-weight fit would land above 1.5, the recency-weighted one below.
     */
    public function testRecentFirstTriesWeighMore(): void
    {
        $parameters = $this->fit([500 => 4.0], recentScale: 1.0);
        $all = $parameters->imageWindowScales[PredictionModelParameters::IMAGE_WINDOW_ALL];

        // 88 days back weighs 0.26 of a first try 3 days back (half-life 45 days)
        self::assertLessThan(1.5, $all[0]);
        self::assertLessThan(1.5, $all[1]);
        self::assertGreaterThan(0.6, $all[1]);
    }

    /**
     * A later solver of a puzzle without a difficulty yet (k = 1-4) scatters otherwise than its first solver: the two
     * get their own scales.
     */
    public function testFirstAndLaterSolversGetTheirOwnScales(): void
    {
        $parameters = $this->fit([500 => 1.0], laterSolverScale: 4.0);
        $first = $parameters->imageWindowScalesFor(PiecesGroup::FiveHundred, ImageKind::Flat, 0);
        $later = $parameters->imageWindowScalesFor(PiecesGroup::FiveHundred, ImageKind::Flat, 2);

        self::assertArrayHasKey('pieces_500|flat|k1-4', $parameters->imageWindowScales);
        self::assertNotNull($first);
        self::assertNotNull($later);
        self::assertGreaterThan($first[1] * 2, $later[1]);
    }

    /**
     * A stored fit from before the window scales keeps its symmetric form: the scale touches only the picture's own
     * term, a prediction without the estimate's variance keeps its window.
     */
    public function testTheSymmetricFormOfAnOlderFit(): void
    {
        $parameters = PredictionModelParameters::defaults();
        $scaled = $parameters->with(imageVarianceScale: 2.0);

        self::assertSame($parameters->firstTrySigma(0.13, 0, PiecesGroup::FiveHundred), $scaled->firstTrySigma(0.13, 0, PiecesGroup::FiveHundred));
        self::assertEqualsWithDelta(
            sqrt(0.20 * 0.13 ** 2 + 2.0 * 0.01 + 0.0072),
            $scaled->firstTrySigma(0.13, 0, PiecesGroup::FiveHundred, 0.01),
            0.000001,
        );

        $stored = PredictionModelParameters::defaults()->with(imageVarianceScale: 1.85, imageVarianceScales: ['up_to_300|flat' => 2.5])->toArray();

        self::assertSame(1.85, PredictionModelParameters::fromArray($stored)->imageVarianceScale);
        self::assertSame(['up_to_300|flat' => 2.5], PredictionModelParameters::fromArray($stored)->imageVarianceScales);
        self::assertSame(2.5, PredictionModelParameters::fromArray($stored)->imageVarianceScaleFor(PiecesGroup::UpTo300, ImageKind::Flat));
    }

    /**
     * @param array<int, float> $trueScale per piece count: how many times the estimate's variance the picture part of
     *                                     the first tries scatters (the players split evenly between the sizes)
     * @param null|float $recentScale when set, the 2nd and 3rd first try of each player come 85 days later (3-5 days
     *                                before the end of the fit's window) and scatter this many times instead
     * @param null|float $laterSolverScale when set, every puzzle is solved by a second player a day after its first
     *                                     solver (k = 1), scattering this many times
     */
    private function fit(array $trueScale, bool $withEstimates = true, null|float $recentScale = null, null|float $laterSolverScale = null): PredictionModelParameters
    {
        $sizes = array_keys($trueScale);
        mt_srand(20261027);
        $timeline = new PaceTimeline(new PlayerBaselineCalculator($this->createStub(Connection::class), new MockClock(TestClock::now())), 1.3);
        $estimate = new ImagePriorInput(0.0, -0.15, 0.15, 'm', ImageKind::Flat);
        $defaults = PredictionModelParameters::defaults();
        $sigma = static function (int $pieces, float $scale) use ($defaults, $estimate): float {
            // The player part as the fit leaves it here (too few samples for its own first-try fit) + the picture part
            return sqrt($defaults->firstTryPlayerVariance(PredictionModelParameters::GLOBAL_SPREAD, PiecesGroup::fromPiecesCount($pieces)) + $scale * $estimate->variance());
        };

        $rows = [];
        $estimates = [];

        // Three first tries each (the spread stays the global one before a 5th), every puzzle new
        for ($player = 0; $player < 1000; $player++) {
            $pieces = $sizes[$player % count($sizes)];
            $timeline->add("p{$player}", $pieces, 1 * self::DAY, 1 * self::DAY, self::BASELINE);

            if ($laterSolverScale !== null) {
                $timeline->add("q{$player}", $pieces, 1 * self::DAY, 1 * self::DAY, self::BASELINE);
            }

            for ($attempt = 0; $attempt < 3; $attempt++) {
                $puzzle = "puzzle-{$player}-{$attempt}";
                $recent = $recentScale !== null && $attempt >= 1;
                $day = $recent ? 95 + $attempt : 10 + $attempt;
                $seconds = (int) round(self::BASELINE * exp($sigma($pieces, $recent ? $recentScale : $trueScale[$pieces]) * self::normal()));
                $rows[] = ['id' => "t-{$player}-{$attempt}", 'player_id' => "p{$player}", 'puzzle_id' => $puzzle, 'pieces_count' => $pieces,
                    'seconds_to_solve' => $seconds, 'solved_ts' => $day * self::DAY, 'tracked_ts' => $day * self::DAY];
                $estimates[$puzzle] = $estimate;

                if ($laterSolverScale !== null) {
                    $later = (int) round(self::BASELINE * exp($sigma($pieces, $laterSolverScale) * self::normal()));
                    $rows[] = ['id' => "u-{$player}-{$attempt}", 'player_id' => "q{$player}", 'puzzle_id' => $puzzle, 'pieces_count' => $pieces,
                        'seconds_to_solve' => $later, 'solved_ts' => ($day + 1) * self::DAY, 'tracked_ts' => ($day + 1) * self::DAY];
                }
            }
        }

        $set = FirstAttemptSet::build($rows, $timeline);
        $engine = new PredictionEngine($set, $timeline, new TimePredictionCalculator());
        $engine->computeAsOfDifficulty();

        return new PredictionModelFitter()->fit(
            $engine,
            PriorLookup::fit($set, []),
            new RepeatHistory(),
            0.0,
            100 * self::DAY,
            new DateTimeImmutable('2026-10-27'),
            $withEstimates ? $estimates : [],
        );
    }

    /**
     * A standard normal draw from the seeded generator (Box-Muller).
     */
    private static function normal(): float
    {
        $u = (mt_rand(1, mt_getrandmax()) / mt_getrandmax());
        $v = (mt_rand(1, mt_getrandmax()) / mt_getrandmax());

        return sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }
}
