<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use SpeedPuzzling\Web\Services\PuzzleTimeGuides;
use SpeedPuzzling\Web\Services\SolveTimeDistributionProvider;
use SpeedPuzzling\Web\Tests\PinsSolveTimeDistributions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PuzzleTimeGuidesTest extends KernelTestCase
{
    use PinsSolveTimeDistributions;

    public function testRouteRequirementListsExactlyTheGenericSizes(): void
    {
        $requirement = array_map('intval', explode('|', PuzzleTimeGuides::GENERIC_GUIDE_PIECES_REQUIREMENT));

        self::assertSame(PuzzleTimeGuides::GENERIC_GUIDE_PIECES, $requirement);
        self::assertNotContains(PuzzleTimeGuides::ORIGINAL_GUIDE_PIECES, PuzzleTimeGuides::GENERIC_GUIDE_PIECES);

        // Every guide size is a bucket the distribution provider computes
        $allGuidePieces = [...PuzzleTimeGuides::GENERIC_GUIDE_PIECES, PuzzleTimeGuides::ORIGINAL_GUIDE_PIECES];
        self::assertSame([], array_diff($allGuidePieces, SolveTimeDistributionProvider::PIECES_BUCKETS));
    }

    public function testEverySizeWithEnoughSoloSolvesIsLive(): void
    {
        self::bootKernel();
        self::pinSolveTimeDistributions();

        $guides = self::getContainer()->get(PuzzleTimeGuides::class);

        self::assertSame([
            100 => '/en/guides/how-long-does-a-100-piece-puzzle-take',
            200 => '/en/guides/how-long-does-a-200-piece-puzzle-take',
            300 => '/en/guides/how-long-does-a-300-piece-puzzle-take',
            500 => '/en/guides/how-long-does-a-500-piece-puzzle-take',
            1000 => '/en/guides/how-long-does-a-1000-piece-puzzle-take',
            1500 => '/en/guides/how-long-does-a-1500-piece-puzzle-take',
            2000 => '/en/guides/how-long-does-a-2000-piece-puzzle-take',
        ], $guides->paths());

        self::assertStringStartsWith('http', $guides->absoluteUrls()[500]);
    }

    public function testSizeBelowTheMinimumIsNotLive(): void
    {
        self::bootKernel();
        self::pinSolveTimeDistributions([
            1500 => PuzzleTimeGuides::MINIMUM_SOLO_SOLVES,
            2000 => PuzzleTimeGuides::MINIMUM_SOLO_SOLVES - 1,
        ]);

        $guides = self::getContainer()->get(PuzzleTimeGuides::class);

        self::assertTrue($guides->isLive(1500));
        self::assertFalse($guides->isLive(2000));
        self::assertArrayNotHasKey(2000, $guides->paths());
    }

    public function testOriginalGuideIsAlwaysLiveAndOtherSizesNever(): void
    {
        self::bootKernel();

        $guides = self::getContainer()->get(PuzzleTimeGuides::class);

        // Fixture data is far below the minimum for every generic size
        self::assertSame([1000 => '/en/guides/how-long-does-a-1000-piece-puzzle-take'], $guides->paths());
        self::assertTrue($guides->isLive(1000));
        self::assertFalse($guides->isLive(500));
        self::assertFalse($guides->isLive(750));
    }
}
