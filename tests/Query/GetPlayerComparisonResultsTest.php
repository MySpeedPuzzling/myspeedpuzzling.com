<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetPlayerComparisonResults;
use SpeedPuzzling\Web\Tests\DataFixtures\ComparisonFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\ComparisonMode;
use SpeedPuzzling\Web\Value\ComparisonSubject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerComparisonResultsTest extends KernelTestCase
{
    private GetPlayerComparisonResults $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPlayerComparisonResults::class);
    }

    public function testSoloReturnsFastestAndFirstTryWithDates(): void
    {
        $results = $this->query->forSubject(new ComparisonSubject(ComparisonFixture::CMP_A), ComparisonMode::Solo);

        self::assertArrayHasKey(PuzzleFixture::PUZZLE_3000, $results);
        $row = $results[PuzzleFixture::PUZZLE_3000];

        // Fastest = best (600, last attempt), first try = 1000 (oldest)
        self::assertSame(600, $row->fastestTime);
        self::assertSame(ComparisonFixture::T_A3_BEST, $row->fastestTimeId);
        self::assertSame(1000, $row->firstTryTime);
        self::assertSame(ComparisonFixture::T_A3_FIRST, $row->firstTryTimeId);

        // Best time was achieved more recently than the first try
        self::assertNotNull($row->firstTryDate);
        self::assertGreaterThan($row->firstTryDate, $row->fastestDate);
    }

    public function testSoloExcludesSuspiciousAndNullTimes(): void
    {
        $results = $this->query->forSubject(new ComparisonSubject(ComparisonFixture::CMP_A), ComparisonMode::Solo);

        self::assertArrayHasKey(PuzzleFixture::PUZZLE_4000, $results);
        $row = $results[PuzzleFixture::PUZZLE_4000];

        // 400 is suspicious and one row has null seconds — both must be ignored
        self::assertSame(900, $row->fastestTime);
        self::assertSame(ComparisonFixture::T_A4_NORMAL, $row->fastestTimeId);
        self::assertSame(900, $row->firstTryTime);
    }

    public function testSoloDoesNotIncludeDuoOrTeamPuzzles(): void
    {
        $results = $this->query->forSubject(new ComparisonSubject(ComparisonFixture::CMP_A), ComparisonMode::Solo);

        self::assertEqualsCanonicalizing(
            [PuzzleFixture::PUZZLE_3000, PuzzleFixture::PUZZLE_4000],
            array_keys($results),
        );
    }

    public function testPairsReturnsDuosWithAnyPartner(): void
    {
        $results = $this->query->forSubject(new ComparisonSubject(ComparisonFixture::CMP_A), ComparisonMode::Pairs);

        self::assertEqualsCanonicalizing(
            [PuzzleFixture::PUZZLE_5000, PuzzleFixture::PUZZLE_6000],
            array_keys($results),
        );
        self::assertSame(2000, $results[PuzzleFixture::PUZZLE_5000]->fastestTime);
    }

    public function testPairsMatchesPlayerWhoIsOnlyATeamMember(): void
    {
        // CMP_B is in the duo on PUZZLE_5000 via the team JSON only (CMP_A is the owner).
        $results = $this->query->forSubject(new ComparisonSubject(ComparisonFixture::CMP_B), ComparisonMode::Pairs);

        self::assertArrayHasKey(PuzzleFixture::PUZZLE_5000, $results);
        self::assertSame(ComparisonFixture::T_DUO_AB, $results[PuzzleFixture::PUZZLE_5000]->fastestTimeId);
    }

    public function testPairsCoSolverNarrowingSelectsTheRightPartner(): void
    {
        $withB = $this->query->forSubject(
            new ComparisonSubject(ComparisonFixture::CMP_A, [ComparisonFixture::CMP_B]),
            ComparisonMode::Pairs,
        );
        self::assertSame([PuzzleFixture::PUZZLE_5000], array_keys($withB));

        $withC = $this->query->forSubject(
            new ComparisonSubject(ComparisonFixture::CMP_A, [ComparisonFixture::CMP_C]),
            ComparisonMode::Pairs,
        );
        self::assertSame([PuzzleFixture::PUZZLE_6000], array_keys($withC));
    }

    public function testPairsCoSolverNarrowingWithNonPartnerReturnsNothing(): void
    {
        $results = $this->query->forSubject(
            new ComparisonSubject(ComparisonFixture::CMP_A, [ComparisonFixture::CMP_D]),
            ComparisonMode::Pairs,
        );

        self::assertSame([], $results);
    }

    public function testTeamsReturnsThreePlayerSolves(): void
    {
        $forOwner = $this->query->forSubject(new ComparisonSubject(ComparisonFixture::CMP_A), ComparisonMode::Teams);
        self::assertSame([ComparisonFixture::CMP_TEAM_PUZZLE], array_keys($forOwner));
        self::assertSame(3000, $forOwner[ComparisonFixture::CMP_TEAM_PUZZLE]->fastestTime);

        // A member who is not the owner still sees the team solve.
        $forMember = $this->query->forSubject(new ComparisonSubject(ComparisonFixture::CMP_C), ComparisonMode::Teams);
        self::assertSame([ComparisonFixture::CMP_TEAM_PUZZLE], array_keys($forMember));
    }

    public function testTeamsCoSolverNarrowing(): void
    {
        $withBoth = $this->query->forSubject(
            new ComparisonSubject(ComparisonFixture::CMP_A, [ComparisonFixture::CMP_B, ComparisonFixture::CMP_C]),
            ComparisonMode::Teams,
        );
        self::assertSame([ComparisonFixture::CMP_TEAM_PUZZLE], array_keys($withBoth));

        $withNonMember = $this->query->forSubject(
            new ComparisonSubject(ComparisonFixture::CMP_A, [ComparisonFixture::CMP_D]),
            ComparisonMode::Teams,
        );
        self::assertSame([], $withNonMember);
    }

    public function testModesAreMutuallyExclusive(): void
    {
        // Solo puzzles never leak into pairs/teams and vice versa.
        $solo = array_keys($this->query->forSubject(new ComparisonSubject(ComparisonFixture::CMP_A), ComparisonMode::Solo));
        $pairs = array_keys($this->query->forSubject(new ComparisonSubject(ComparisonFixture::CMP_A), ComparisonMode::Pairs));
        $teams = array_keys($this->query->forSubject(new ComparisonSubject(ComparisonFixture::CMP_A), ComparisonMode::Teams));

        self::assertSame([], array_intersect($solo, $pairs));
        self::assertSame([], array_intersect($solo, $teams));
        self::assertSame([], array_intersect($pairs, $teams));
    }

    public function testReturnsEmptyForInvalidUuid(): void
    {
        self::assertSame([], $this->query->forSubject(new ComparisonSubject('not-a-uuid'), ComparisonMode::Solo));
    }

    public function testReturnsEmptyForPlayerWithoutResults(): void
    {
        // CMP_D has no solving times at all.
        self::assertSame([], $this->query->forSubject(new ComparisonSubject(ComparisonFixture::CMP_D), ComparisonMode::Solo));
    }
}
