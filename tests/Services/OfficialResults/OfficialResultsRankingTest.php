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

    /**
     * Divisions (shared-puzzles-and-divisions.md A13): the overall rules inside each division - ties share, the next rank
     * skips, unfinished after finished, did not start / no result / no division unranked; input order kept.
     */
    public function testRankWithinRanksEveryDivisionOnItsOwn(): void
    {
        $results = [
            'regular-fast' => RoundEntryResult::finished(3000),
            'senior-tie-a' => RoundEntryResult::finished(4200),
            'regular-slow' => RoundEntryResult::finished(5000),
            'senior-tie-b' => RoundEntryResult::finished(4200),
            'senior-unfinished' => RoundEntryResult::unfinished(900),
            'senior-fastest' => RoundEntryResult::finished(4000),
            'junior-dns' => RoundEntryResult::didNotStart(),
            'junior-none' => RoundEntryResult::none(),
            'junior-only' => RoundEntryResult::unfinished(10),
            'no-division' => RoundEntryResult::finished(1000),
        ];
        $divisions = [
            'regular-fast' => 'regular',
            'senior-tie-a' => 'senior',
            'regular-slow' => 'regular',
            'senior-tie-b' => 'senior',
            'senior-unfinished' => 'senior',
            'senior-fastest' => 'senior',
            'junior-dns' => 'junior',
            'junior-none' => 'junior',
            'junior-only' => 'junior',
            'no-division' => null,
        ];

        self::assertSame([
            'regular-fast' => 1,
            'senior-tie-a' => 2,
            'regular-slow' => 2,
            'senior-tie-b' => 2,
            'senior-unfinished' => 4,
            'senior-fastest' => 1,
            'junior-dns' => null,
            'junior-none' => null,
            'junior-only' => 1,
            'no-division' => null,
        ], OfficialResultsRanking::rankWithin($results, $divisions));

        // The overall rank is untouched by divisions
        self::assertSame(1, OfficialResultsRanking::rank($results)['no-division']);
    }

    public function testRankWithinWithoutGroupsRanksNobody(): void
    {
        self::assertSame([], OfficialResultsRanking::rankWithin([], []));
        self::assertSame([0 => null, 1 => null], OfficialResultsRanking::rankWithin([RoundEntryResult::finished(10), RoundEntryResult::finished(20)], []));
    }
}
