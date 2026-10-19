<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\CloseCallCandidate;
use SpeedPuzzling\Web\Services\Insights\CloseCallsBuilder;
use SpeedPuzzling\Web\Services\Insights\InsightsCriteria;

final class CloseCallsBuilderTest extends TestCase
{
    public function testTheGapToTheNextMilestonePlace(): void
    {
        $near = InsightsTestResults::result();
        $far = InsightsTestResults::result();
        $first = InsightsTestResults::result();
        $big = InsightsTestResults::result(['piecesCount' => 1000]);

        $candidates = [
            $near->puzzleId => new CloseCallCandidate($near->puzzleId, 2500, 12, 2480, [1 => 2000, 3 => 2100, 10 => 2450]),
            $far->puzzleId => new CloseCallCandidate($far->puzzleId, 3000, 4, 2700, [1 => 2000, 3 => 2600]),
            $first->puzzleId => new CloseCallCandidate($first->puzzleId, 1800, 1, null, []),
            $big->puzzleId => new CloseCallCandidate($big->puzzleId, 9000, 2, 8990, [1 => 8990]),
            // A puzzle without a counted result of the player (a secret one) is never a close call
            'ffffffff-0000-7000-8000-000000000000' => new CloseCallCandidate('ffffffff-0000-7000-8000-000000000000', 100, 2, 99, [1 => 99]),
        ];
        $puzzles = [$near->puzzleId => $near, $far->puzzleId => $far, $first->puzzleId => $first, $big->puzzleId => $big];

        $section = (new CloseCallsBuilder())->build($candidates, $puzzles, InsightsCriteria::fromUserInput(['pieces' => '500'], isMember: false));

        self::assertSame(3, $section->puzzleCount);
        self::assertSame(1, $section->firstPlaceCount);
        self::assertSame(1, $section->podiumCount);
        self::assertSame(2, $section->topTenCount);
        self::assertSame(2, $section->candidateCount);
        self::assertSame(1, $section->closeCount, '50 s of 2500 s = 2 %');

        $closest = $section->items[0];
        self::assertSame($near->puzzleId, $closest->puzzleId);
        self::assertSame(10, $closest->targetRank);
        self::assertSame(50, $closest->targetGapSeconds);
        self::assertEqualsWithDelta(0.02, $closest->targetGapShare, 1e-12);
        self::assertSame(20, $closest->nextPlaceGapSeconds);

        self::assertSame(3, $section->items[1]->targetRank);
        self::assertSame(400, $section->items[1]->targetGapSeconds);
    }
}
