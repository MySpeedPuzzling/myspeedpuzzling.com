<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\ResultRatingStatus;

final class ResultRatingStatusTest extends TestCase
{
    /**
     * @param array<string, bool|int|null> $overrides
     */
    #[DataProvider('cases')]
    public function testStatus(array $overrides, string $status, null|string $reason): void
    {
        $arguments = $overrides + [
            'ratedPieceCount' => true,
            'solo' => true,
            'timed' => true,
            'suspicious' => false,
            'playerPrivate' => false,
            'countsFirstAttempt' => null,
            'countsBestTime' => null,
            'puzzleQualifies' => true,
            'puzzleFirstTrySolvers' => 25,
            'candidate' => true,
            'inWindow' => true,
        ];

        /** @phpstan-ignore argument.type */
        $result = ResultRatingStatus::of(...$arguments);

        self::assertSame($status, $result->status);
        self::assertSame($reason, $result->reason);
    }

    /**
     * @return iterable<string, array{array<string, bool|int|null>, string, null|string}>
     */
    public static function cases(): iterable
    {
        $notCounted = ResultRatingStatus::NOT_COUNTED;

        yield 'other piece count' => [['ratedPieceCount' => false, 'solo' => false], $notCounted, ResultRatingStatus::REASON_NOT_RATED_PIECE_COUNT];
        yield 'pair' => [['solo' => false], $notCounted, ResultRatingStatus::REASON_NOT_SOLO];
        yield 'no time' => [['timed' => false], $notCounted, ResultRatingStatus::REASON_NO_TIME];
        yield 'needs verification' => [['suspicious' => true], $notCounted, ResultRatingStatus::REASON_NEEDS_VERIFICATION];
        yield 'private' => [['playerPrivate' => true, 'countsFirstAttempt' => true], $notCounted, ResultRatingStatus::REASON_PRIVATE_PROFILE];
        yield 'both' => [['countsFirstAttempt' => true, 'countsBestTime' => true], ResultRatingStatus::FIRST_ATTEMPT_AND_BEST_TIME, null];
        yield 'first attempt' => [['countsFirstAttempt' => true, 'countsBestTime' => false], ResultRatingStatus::FIRST_ATTEMPT, null];
        yield 'best time' => [['countsBestTime' => true], ResultRatingStatus::BEST_TIME, null];
        yield 'outside the best 100' => [['countsFirstAttempt' => false], $notCounted, ResultRatingStatus::REASON_OUTSIDE_TOP];
        yield 'too few solvers' => [['puzzleQualifies' => false, 'puzzleFirstTrySolvers' => 14], $notCounted, ResultRatingStatus::REASON_TOO_FEW_SOLVERS];
        yield 'difficulty not settled' => [['puzzleQualifies' => false, 'puzzleFirstTrySolvers' => 20], $notCounted, ResultRatingStatus::REASON_DIFFICULTY_NOT_SETTLED];
        yield 'another result of the puzzle' => [['candidate' => false], $notCounted, ResultRatingStatus::REASON_NOT_BEST_TIME];
        yield 'too old' => [['inWindow' => false], $notCounted, ResultRatingStatus::REASON_OLDER_THAN_WINDOW];
        yield 'after the last run' => [[], $notCounted, ResultRatingStatus::REASON_NOT_COMPUTED_YET];
    }
}
