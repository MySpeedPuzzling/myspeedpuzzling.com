<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\InvalidPiecesPlaced;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultNeedsTimeLimit;
use SpeedPuzzling\Web\Exceptions\UnfinishedTotalTimeWithinLimit;
use SpeedPuzzling\Web\Value\UnfinishedResult;

/**
 * The one rule of an unfinished result (docs/features/competitions-management/unfinished-results-plan.md, step 3) - every
 * boundary: 0, 1, count - 1, count; limit × 60, limit × 60 + 1; no limit.
 */
final class UnfinishedResultTest extends TestCase
{
    public function testStoppedWhenTheTimeRanOutSpendsTheLimit(): void
    {
        $result = UnfinishedResult::fromInput(piecesPlaced: 479, totalSeconds: null, puzzlePiecesCount: 500, roundMinutesLimit: 90);

        self::assertSame(479, $result->piecesPlaced);
        self::assertNull($result->finishedLaterSeconds);
        self::assertSame(5400, $result->secondsSpent);
    }

    public function testFinishedAfterwardsSpendsTheTotal(): void
    {
        $result = UnfinishedResult::fromInput(piecesPlaced: 479, totalSeconds: 6492, puzzlePiecesCount: 500, roundMinutesLimit: 90);

        self::assertSame(6492, $result->finishedLaterSeconds);
        self::assertSame(6492, $result->secondsSpent);
    }

    public function testOneSecondOverTheLimitIsAnUnfinishedTotal(): void
    {
        $result = UnfinishedResult::fromInput(piecesPlaced: 1, totalSeconds: 5401, puzzlePiecesCount: 500, roundMinutesLimit: 90);

        self::assertSame(1, $result->piecesPlaced);
        self::assertSame(5401, $result->secondsSpent);
    }

    public function testOneLessThanAllPiecesIsUnfinished(): void
    {
        self::assertSame(499, UnfinishedResult::fromInput(499, null, 500, 90)->piecesPlaced);
    }

    #[DataProvider('invalidPieces')]
    public function testPiecesOutsideOneToCountMinusOneAreRefused(int $pieces, bool $allPlaced): void
    {
        try {
            UnfinishedResult::fromInput($pieces, null, 500, 90);
            self::fail('Expected InvalidPiecesPlaced');
        } catch (InvalidPiecesPlaced $exception) {
            self::assertSame($allPlaced, $exception->allPlaced());
        }
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function invalidPieces(): iterable
    {
        yield 'zero' => [0, false];
        yield 'negative' => [-3, false];
        yield 'all of them' => [500, true];
        yield 'more than the puzzle has' => [501, true];
    }

    public function testATotalWithinTheLimitIsRefused(): void
    {
        $this->expectException(UnfinishedTotalTimeWithinLimit::class);

        UnfinishedResult::fromInput(479, 5400, 500, 90);
    }

    public function testATotalOfZeroIsRefused(): void
    {
        $this->expectException(UnfinishedTotalTimeWithinLimit::class);

        UnfinishedResult::fromInput(479, 0, 500, 90);
    }

    public function testNoLimitIsRefusedBeforeAnythingElse(): void
    {
        $this->expectException(UnfinishedResultNeedsTimeLimit::class);

        // Even with invalid pieces: without a round's limit there is nothing to be unfinished against
        UnfinishedResult::fromInput(0, null, 500, null);
    }

    public function testEquals(): void
    {
        self::assertTrue(UnfinishedResult::fromInput(479, null, 500, 90)->equals(UnfinishedResult::fromInput(479, null, 500, 90)));
        self::assertFalse(UnfinishedResult::fromInput(479, null, 500, 90)->equals(UnfinishedResult::fromInput(479, null, 500, 60)));
        self::assertFalse(UnfinishedResult::fromInput(479, null, 500, 90)->equals(UnfinishedResult::fromInput(480, null, 500, 90)));
    }
}
