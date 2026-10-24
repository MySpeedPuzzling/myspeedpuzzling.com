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
use Symfony\Component\Clock\MockClock;

/**
 * The picture-only window's scale (PredictionModelFitter step 6): the estimate's interval is the puzzle term of a
 * prediction resting on the picture alone, and the daily fit widens it until those windows hold what they say.
 */
final class ImageWindowScaleFitTest extends TestCase
{
    private const float DAY = 86400.0;
    private const int BASELINE = 3000;

    /**
     * 3,000 first tries at new puzzles (k = 0) scattering twice as much around the picture-only prediction as the
     * estimate's interval says: with the interval as is the windows hold too few, with the fitted scale 50 / 80 %.
     */
    public function testTheFitWidensThePictureOnlyWindowUntilItHoldsWhatItSays(): void
    {
        $parameters = $this->fit(trueScale: 2.0);
        $summary = $parameters->summary;

        self::assertSame(3000, $summary['image_scale_samples']);
        self::assertLessThan(0.45, $summary['image_window50_hit_unscaled']);
        self::assertLessThan(0.75, $summary['image_window80_hit_unscaled']);
        self::assertGreaterThan(1.5, $parameters->imageVarianceScale);
        self::assertEqualsWithDelta(0.50, $summary['image_window50_hit'], 0.03);
        self::assertEqualsWithDelta(0.80, $summary['image_window80_hit'], 0.03);
    }

    public function testWithoutEstimatesTheScaleStaysOne(): void
    {
        $parameters = $this->fit(trueScale: 2.0, withEstimates: false);

        self::assertSame(1.0, $parameters->imageVarianceScale);
        self::assertSame(0, $parameters->summary['image_scale_samples']);
    }

    /**
     * The scale touches only the picture's own term: a prediction without the estimate's variance keeps its window.
     */
    public function testTheScaleWidensOnlyThePictureOnlyWindow(): void
    {
        $parameters = PredictionModelParameters::defaults();
        $scaled = $parameters->with(imageVarianceScale: 2.0);

        self::assertSame($parameters->firstTrySigma(0.13, 0, PiecesGroup::FiveHundred), $scaled->firstTrySigma(0.13, 0, PiecesGroup::FiveHundred));
        self::assertEqualsWithDelta(
            sqrt(0.20 * 0.13 ** 2 + 2.0 * 0.01 + 0.0072),
            $scaled->firstTrySigma(0.13, 0, PiecesGroup::FiveHundred, 0.01),
            0.000001,
        );
        self::assertGreaterThan($parameters->firstTrySigma(0.13, 0, PiecesGroup::FiveHundred, 0.01), $scaled->firstTrySigma(0.13, 0, PiecesGroup::FiveHundred, 0.01));
    }

    /**
     * 300-piece first tries scatter three times the interval, 500-piece ones 1.5 times: one global scale would leave
     * both off, each size group gets its own (and its kind), and a group without enough first tries the global one.
     */
    public function testEachSizeGroupAndKindGetsItsOwnScale(): void
    {
        $parameters = $this->fit(trueScale: [300 => 3.0, 500 => 1.5]);
        $summary = $parameters->summary;
        $small = PredictionModelParameters::imageScaleKey(PiecesGroup::UpTo300, ImageKind::Flat);
        $five = PredictionModelParameters::imageScaleKey(PiecesGroup::FiveHundred, ImageKind::Flat);

        self::assertGreaterThan($parameters->imageVarianceScales[$five] * 1.4, $parameters->imageVarianceScales[$small]);
        self::assertSame($parameters->imageVarianceScales[$small], $parameters->imageVarianceScaleFor(PiecesGroup::UpTo300, ImageKind::Flat));
        self::assertSame($parameters->imageVarianceScales[PiecesGroup::UpTo300->value], $parameters->imageVarianceScaleFor(PiecesGroup::UpTo300, ImageKind::Box), 'Kind without its own fit: the size group');
        self::assertSame($parameters->imageVarianceScale, $parameters->imageVarianceScaleFor(PiecesGroup::Thousand, ImageKind::Flat), 'Group without first tries: the global one');
        self::assertSame(1500, $summary['image_scale_samples_' . $small]);
    }

    public function testTheScaleIsStoredAndAnOlderFitLoadsWithOne(): void
    {
        $stored = PredictionModelParameters::defaults()->with(imageVarianceScale: 1.85, imageVarianceScales: ['up_to_300|flat' => 2.5])->toArray();

        self::assertSame(1.85, PredictionModelParameters::fromArray($stored)->imageVarianceScale);
        self::assertSame(['up_to_300|flat' => 2.5], PredictionModelParameters::fromArray($stored)->imageVarianceScales);

        $firstTry = $stored['first_try'];
        self::assertIsArray($firstTry);
        unset($firstTry['image_variance_scale'], $firstTry['image_variance_scales']);
        $stored['first_try'] = $firstTry;

        self::assertSame(1.0, PredictionModelParameters::fromArray($stored)->imageVarianceScale);
        self::assertSame([], PredictionModelParameters::fromArray($stored)->imageVarianceScales);
    }

    /**
     * @param float|array<int, float> $trueScale one scale, or per piece count (the players split evenly between them)
     */
    private function fit(float|array $trueScale, bool $withEstimates = true): PredictionModelParameters
    {
        $scales = is_array($trueScale) ? $trueScale : [500 => $trueScale];
        $sizes = array_keys($scales);
        mt_srand(20261023);
        $timeline = new PaceTimeline(new PlayerBaselineCalculator($this->createStub(Connection::class), new MockClock()), 1.3);
        $estimate = new ImagePriorInput(0.0, -0.15, 0.15, 'm', ImageKind::Flat);
        $defaults = PredictionModelParameters::defaults();
        // Spread and size terms of the window as the fit leaves them here (too few samples for its own first-try fit)
        $sigmas = [];

        foreach ($scales as $pieces => $scale) {
            $sigmas[$pieces] = sqrt($defaults->firstTryPlayerSpreadWeight * PredictionModelParameters::GLOBAL_SPREAD ** 2
                + $scale * $estimate->variance()
                + $defaults->firstTrySizeTerms[PiecesGroup::fromPiecesCount($pieces)->value]);
        }

        $rows = [];
        $estimates = [];

        // Three first tries each (the spread stays the global one before a 5th), every puzzle new
        for ($player = 0; $player < 1000; $player++) {
            $pieces = $sizes[$player % count($sizes)];
            $timeline->add("p{$player}", $pieces, 1 * self::DAY, 1 * self::DAY, self::BASELINE);

            for ($attempt = 0; $attempt < 3; $attempt++) {
                $puzzle = "puzzle-{$player}-{$attempt}";
                $seconds = (int) round(self::BASELINE * exp($sigmas[$pieces] * self::normal()));
                $rows[] = ['id' => "t-{$player}-{$attempt}", 'player_id' => "p{$player}", 'puzzle_id' => $puzzle, 'pieces_count' => $pieces,
                    'seconds_to_solve' => $seconds, 'solved_ts' => (10 + $attempt) * self::DAY, 'tracked_ts' => (10 + $attempt) * self::DAY];
                $estimates[$puzzle] = $estimate;
            }
        }

        $set = FirstAttemptSet::build($rows, $timeline);
        $engine = new PredictionEngine($set, $timeline, new TimePredictionCalculator());
        $engine->computeAsOfDifficulty();

        return (new PredictionModelFitter())->fit(
            $engine,
            PriorLookup::fit($set, []),
            new RepeatHistory(),
            0.0,
            100 * self::DAY,
            new DateTimeImmutable('2026-10-23'),
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
