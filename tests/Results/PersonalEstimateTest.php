<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\CollectionDifficultyRank;
use SpeedPuzzling\Web\Results\PersonalEstimate;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\EstimateBasis;
use SpeedPuzzling\Web\Value\EstimateCertainty;

/**
 * How a personal estimate is shown (docs/features/insights/estimates.md): the certainty bar (the 80 % range on the
 * time × 0.5 … × 1.5), how sure in words, what is marked as estimated, and the puzzle page's collection context.
 */
final class PersonalEstimateTest extends TestCase
{
    public function testTheBarAndTheCertaintyFollowThe80PercentRange(): void
    {
        // A measured 500 on a steady player: 80 % range 3240-4080 s around 3600 s (23 % wide)
        $measured = self::estimate(EstimateBasis::Measured, 3600, 3420, 3840, 3240, 4080);
        self::assertSame(['left' => 40, 'right' => 37], $measured->bar());
        self::assertSame(EstimateCertainty::High, $measured->certainty());
        self::assertFalse($measured->isEstimated());

        // A picture-only estimate: 80 % range 2520-4680 s (60 % wide) - wide, a rough estimate, marked as estimated
        $picture = self::estimate(EstimateBasis::Picture, 3600, 3060, 4200, 2520, 4680);
        self::assertSame(['left' => 20, 'right' => 20], $picture->bar());
        self::assertSame(EstimateCertainty::Low, $picture->certainty());
        self::assertTrue($picture->isEstimated());

        // Beyond the bar's scale the segment stops short of its ends
        $wild = self::estimate(EstimateBasis::FirstSolves, 3600, 1800, 7200, 900, 9000);
        self::assertSame(['left' => 0, 'right' => 0], $wild->bar());
        self::assertSame(EstimateCertainty::Medium, self::estimate(EstimateBasis::FirstSolves, 3600, 3000, 4300, 2900, 4600)->certainty());
    }

    public function testTheShownTimeAndWindows(): void
    {
        $estimate = self::estimate(EstimateBasis::Measured, 3520, 3300, 3780, 3120, 4020);

        self::assertSame(3600, $estimate->roundedSeconds(), '58:40 → 1 h');
        self::assertSame([3300, 3780], [$estimate->window()?->lowSeconds, $estimate->window()?->highSeconds]);
        self::assertSame([3120, 4020], [$estimate->range80()?->lowSeconds, $estimate->range80()?->highSeconds]);
    }

    public function testACommunityTimeHasNoWindowNoBarAndNoCertainty(): void
    {
        $community = new PersonalEstimate('p', 500, EstimateBasis::Community, null, 7, DifficultyTier::Hard, false, communitySeconds: 3470, communityPlayers: 312);

        self::assertTrue($community->isCommunity());
        self::assertFalse($community->isEstimated());
        self::assertSame(3470, $community->seconds());
        self::assertSame(3600, $community->roundedSeconds());
        self::assertNull($community->window());
        self::assertNull($community->range80());
        self::assertNull($community->bar());
        self::assertNull($community->certainty());
        self::assertSame(312, $community->basisCount());
    }

    public function testTheCollectionContextIsSaidOnlyWhenWorthSaying(): void
    {
        self::assertSame(['hardest' => true, 'rank' => 2], (new CollectionDifficultyRank(true, harder: 1, easier: 10, others: 11))->context());
        self::assertSame(['hardest' => true, 'rank' => 1], (new CollectionDifficultyRank(true, harder: 0, easier: 6, others: 6))->context());
        self::assertSame(['hardest' => false, 'rank' => 3], (new CollectionDifficultyRank(true, harder: 9, easier: 2, others: 11))->context());
        // In the middle of the pack
        self::assertNull((new CollectionDifficultyRank(true, harder: 5, easier: 5, others: 10))->context());
        // Too few of that size to rank
        self::assertNull((new CollectionDifficultyRank(true, harder: 0, easier: 3, others: 3))->context());
        // Not in the player's collection
        self::assertNull((new CollectionDifficultyRank(false, harder: 0, easier: 10, others: 10))->context());
    }

    private static function estimate(EstimateBasis $basis, int $seconds, int $low, int $high, int $low80, int $high80): PersonalEstimate
    {
        return new PersonalEstimate(
            'p',
            500,
            $basis,
            new TimePredictionResult($seconds, $low, $high, 1.0, range80LowSeconds: $low80, range80HighSeconds: $high80),
            $basis === EstimateBasis::Measured ? 12 : 0,
            DifficultyTier::Average,
            $basis->isEstimated(),
        );
    }
}
