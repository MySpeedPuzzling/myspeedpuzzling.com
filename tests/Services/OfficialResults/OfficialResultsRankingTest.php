<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OfficialResults;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\OfficialResultsRanking;
use SpeedPuzzling\Web\Value\RoundEntryResult;

final class OfficialResultsRankingTest extends TestCase
{
    public function testFinishedByTimeThenUnfinishedByPiecesPlacedWithSharedRanks(): void
    {
        $ranks = OfficialResultsRanking::rank([
            'slow' => RoundEntryResult::finished(4200),
            'none' => RoundEntryResult::none(),
            'fast' => RoundEntryResult::finished(3600),
            'tied' => RoundEntryResult::finished(4200),
            'unfinished-few' => RoundEntryResult::unfinished(400),
            'dns' => RoundEntryResult::didNotStart(),
            'unfinished-many' => RoundEntryResult::unfinished(850),
            'unfinished-many-too' => RoundEntryResult::unfinished(850),
            'slowest' => RoundEntryResult::finished(5000),
        ]);

        self::assertSame([
            'slow' => 2,
            'none' => null,
            'fast' => 1,
            'tied' => 2,
            'unfinished-few' => 7,
            'dns' => null,
            'unfinished-many' => 5,
            'unfinished-many-too' => 5,
            'slowest' => 4,
        ], $ranks);
    }

    public function testAnUnfinishedResultNeverBeatsAFinishedOne(): void
    {
        $ranks = OfficialResultsRanking::rank([
            'unfinished' => RoundEntryResult::unfinished(999),
            'finished' => RoundEntryResult::finished(86399),
        ]);

        self::assertSame(['unfinished' => 2, 'finished' => 1], $ranks);
    }

    public function testOrderPutsDidNotStartBeforeNoResult(): void
    {
        $results = [
            RoundEntryResult::none(),
            RoundEntryResult::didNotStart(),
            RoundEntryResult::unfinished(10),
            RoundEntryResult::finished(100),
        ];
        usort($results, OfficialResultsRanking::compare(...));

        self::assertSame([
            ['seconds' => 100],
            ['piecesPlaced' => 10],
            ['didNotStart' => true],
            null,
        ], array_map(static fn (RoundEntryResult $result): null|array => $result->toWire(), $results));
    }

    public function testNothingRankedWithoutResults(): void
    {
        self::assertSame([], OfficialResultsRanking::rank([]));
        self::assertSame([0 => null, 1 => null], OfficialResultsRanking::rank([RoundEntryResult::none(), RoundEntryResult::didNotStart()]));
    }
}
