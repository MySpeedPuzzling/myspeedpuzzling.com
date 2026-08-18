<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetPuzzlePickerSuggestions;
use SpeedPuzzling\Web\Results\PuzzlePickerPick;
use SpeedPuzzling\Web\Results\PuzzlePickerSuggestion;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzlePickerCriteria;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class GetPuzzlePickerSuggestionsTest extends KernelTestCase
{
    private GetPuzzlePickerSuggestions $getPuzzlePickerSuggestions;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->getPuzzlePickerSuggestions = $container->get(GetPuzzlePickerSuggestions::class);
        $this->database = $container->get(Connection::class);
    }

    public function testSameSeedGivesTheSameOrderAndOffsetPagingNeverRepeats(): void
    {
        $criteria = $this->criteria(['source' => 'any', 'seed' => 'abcd1234']);

        $firstPage = $this->getPuzzlePickerSuggestions->pick($criteria, PlayerFixture::PLAYER_REGULAR, 6);
        $firstPageAgain = $this->getPuzzlePickerSuggestions->pick($criteria, PlayerFixture::PLAYER_REGULAR, 6);
        $secondPage = $this->getPuzzlePickerSuggestions->pick($criteria, PlayerFixture::PLAYER_REGULAR, 6, 6);
        $everything = $this->getPuzzlePickerSuggestions->pick($criteria, PlayerFixture::PLAYER_REGULAR, 1000);

        self::assertCount(6, $firstPage->suggestions);
        self::assertSame(self::ids($firstPage), self::ids($firstPageAgain), 'Same seed, same order');
        self::assertSame([], array_intersect(self::ids($firstPage), self::ids($secondPage)), 'Offset paging must not repeat');
        self::assertSame(array_slice(self::ids($everything), 0, 12), [...self::ids($firstPage), ...self::ids($secondPage)], 'Pages are windows of one seeded order');
        self::assertSame($everything->totalMatching, $firstPage->totalMatching);
        self::assertCount($everything->totalMatching, $everything->suggestions);
        self::assertSame(self::ids($everything), array_values(array_unique(self::ids($everything))));

        $otherSeed = $this->getPuzzlePickerSuggestions->pick($this->criteria(['source' => 'any', 'seed' => 'zzzz9999']), PlayerFixture::PLAYER_REGULAR, 1000);

        self::assertEqualsCanonicalizing(self::ids($everything), self::ids($otherSeed), 'A different seed shuffles the same pool');
        self::assertNotSame(self::ids($everything), self::ids($otherSeed), 'A different seed gives a different order');
    }

    public function testMineIsShelfPlusBorrowedMinusLentOut(): void
    {
        // PLAYER_REGULAR: 9 distinct collection items, PUZZLE_2000 borrowed (already an item),
        // 4 puzzles lent out (1500_02 and 3000 are items, 500_04 / 500_05 are not).
        $mine = $this->pickAll(['source' => 'mine'], PlayerFixture::PLAYER_REGULAR);

        self::assertEqualsCanonicalizing([
            PuzzleFixture::PUZZLE_500_01,
            PuzzleFixture::PUZZLE_500_02,
            PuzzleFixture::PUZZLE_500_03,
            PuzzleFixture::PUZZLE_1000_01,
            PuzzleFixture::PUZZLE_1000_02,
            PuzzleFixture::PUZZLE_1500_01,
            PuzzleFixture::PUZZLE_2000,
        ], self::ids($mine));
        self::assertSame(7, $mine->totalMatching);

        foreach ($mine->suggestions as $suggestion) {
            self::assertTrue($suggestion->inMyCollection);
            self::assertFalse($suggestion->isLentOut);
            self::assertSame($suggestion->puzzleId === PuzzleFixture::PUZZLE_2000, $suggestion->isBorrowed);
        }

        $withLentOut = $this->pickAll(['source' => 'mine', 'lent' => '1'], PlayerFixture::PLAYER_REGULAR);

        self::assertEqualsCanonicalizing(
            [...self::ids($mine), PuzzleFixture::PUZZLE_1500_02, PuzzleFixture::PUZZLE_3000],
            self::ids($withLentOut),
        );
        self::assertTrue(self::byId($withLentOut, PuzzleFixture::PUZZLE_3000)->isLentOut);
        self::assertFalse(self::byId($withLentOut, PuzzleFixture::PUZZLE_500_01)->isLentOut);
    }

    public function testBorrowedPuzzlesCountAsMineEvenWithoutCollectionItems(): void
    {
        // PLAYER_WITH_FAVORITES owns nothing in collections but holds two borrowed puzzles
        $mine = $this->pickAll(['source' => 'mine'], PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertEqualsCanonicalizing([PuzzleFixture::PUZZLE_500_03, PuzzleFixture::PUZZLE_500_04], self::ids($mine));

        foreach ($mine->suggestions as $suggestion) {
            self::assertTrue($suggestion->isBorrowed);
            self::assertFalse($suggestion->inMyCollection);
        }
    }

    public function testNotMineExcludesCollectionItemsAndLentOutPuzzles(): void
    {
        $mine = $this->pickAll(['source' => 'mine'], PlayerFixture::PLAYER_REGULAR);
        $notMine = $this->pickAll(['source' => 'not_mine'], PlayerFixture::PLAYER_REGULAR);
        $any = $this->pickAll(['source' => 'any'], PlayerFixture::PLAYER_REGULAR);

        // Other fixtures add puzzles of their own, so pin the algebra rather than the exact list
        self::assertSame([], array_intersect(self::ids($mine), self::ids($notMine)));
        self::assertEqualsCanonicalizing(self::ids($any), [...self::ids($mine), ...self::ids($notMine)], 'mine + not_mine = any (borrowed PUZZLE_2000 is also a collection item)');
        self::assertSame($any->totalMatching, $mine->totalMatching + $notMine->totalMatching);

        foreach ([PuzzleFixture::PUZZLE_300, PuzzleFixture::PUZZLE_1000_03, PuzzleFixture::PUZZLE_1000_04, PuzzleFixture::PUZZLE_9000] as $puzzleId) {
            self::assertContains($puzzleId, self::ids($notMine));
        }

        foreach ($notMine->suggestions as $suggestion) {
            self::assertFalse($suggestion->inMyCollection);
            self::assertFalse($suggestion->isLentOut);
        }

        // 500_04 / 500_05 are not on the shelf but lent out by PLAYER_REGULAR - hidden until asked for
        self::assertNotContains(PuzzleFixture::PUZZLE_500_04, self::ids($notMine));
        self::assertNotContains(PuzzleFixture::PUZZLE_500_05, self::ids($notMine));

        $withLentOut = $this->pickAll(['source' => 'not_mine', 'lent' => '1'], PlayerFixture::PLAYER_REGULAR);

        self::assertEqualsCanonicalizing(
            [...self::ids($notMine), PuzzleFixture::PUZZLE_500_04, PuzzleFixture::PUZZLE_500_05],
            self::ids($withLentOut),
        );
    }

    public function testAnyCoversEveryApprovedVisiblePuzzleAndNeverAnUnapprovedOrHiddenOne(): void
    {
        // PLAYER_WITH_FAVORITES has nothing lent out, so "any" is the whole visible pool
        $any = $this->pickAll(['source' => 'any'], PlayerFixture::PLAYER_WITH_FAVORITES);

        $approvedCount = $this->database->fetchOne(
            'SELECT count(*) FROM puzzle WHERE approved = true AND (hide_until IS NULL OR hide_until < now())',
        );
        assert(is_int($approvedCount));

        self::assertSame($approvedCount, $any->totalMatching);
        self::assertNotContains(PuzzleFixture::PUZZLE_UNAPPROVED, self::ids($any));
        self::assertNotContains(CompetitionApiFixture::PUZZLE_PLATFORM_HIDDEN, self::ids($any), 'hide_until in the future keeps a puzzle out');
        self::assertContains(CompetitionApiFixture::PUZZLE_PLATFORM_IMAGE_HIDDEN, self::ids($any));
        self::assertNull(self::byId($any, CompetitionApiFixture::PUZZLE_PLATFORM_IMAGE_HIDDEN)->puzzleImage, 'hide_image_until blanks the image, not the puzzle');
        self::assertNull(self::byId($any, PuzzleFixture::PUZZLE_HIDDEN_IMAGE)->puzzleImageRatio);
    }

    public function testAnyExcludesLentOutPuzzlesUnlessIncluded(): void
    {
        $any = $this->pickAll(['source' => 'any'], PlayerFixture::PLAYER_REGULAR);
        $withLentOut = $this->pickAll(['source' => 'any', 'lent' => '1'], PlayerFixture::PLAYER_REGULAR);

        self::assertNotContains(PuzzleFixture::PUZZLE_3000, self::ids($any));
        self::assertContains(PuzzleFixture::PUZZLE_3000, self::ids($withLentOut));
        self::assertSame($any->totalMatching + 4, $withLentOut->totalMatching, 'PLAYER_REGULAR has four puzzles lent out');
    }

    public function testSolvedSemanticsIncludeTeamParticipationAndUntimedRows(): void
    {
        // PLAYER_REGULAR owns rows on 8 puzzles: 500_01, 500_02, 500_03, 1000_01 (duo team-001),
        // 1000_02, 1500_01, 2000 and 1000_03 (team-002 owner)
        $solved = $this->pickAll(['source' => 'any', 'solved' => 'before', 'lent' => '1'], PlayerFixture::PLAYER_REGULAR);

        foreach (
            [
            PuzzleFixture::PUZZLE_500_01,
            PuzzleFixture::PUZZLE_500_02,
            PuzzleFixture::PUZZLE_500_03,
            PuzzleFixture::PUZZLE_1000_01,
            PuzzleFixture::PUZZLE_1000_02,
            PuzzleFixture::PUZZLE_1000_03,
            PuzzleFixture::PUZZLE_1500_01,
            PuzzleFixture::PUZZLE_2000,
            ] as $puzzleId
        ) {
            self::assertContains($puzzleId, self::ids($solved));
        }

        foreach ($solved->suggestions as $suggestion) {
            self::assertGreaterThan(0, $suggestion->mySolveCountAny);
        }

        foreach ([PuzzleFixture::PUZZLE_1000_04, PuzzleFixture::PUZZLE_9000, PuzzleFixture::PUZZLE_3000] as $puzzleId) {
            self::assertNotContains($puzzleId, self::ids($solved));
        }

        $never = $this->pickAll(['source' => 'any', 'solved' => 'never', 'lent' => '1'], PlayerFixture::PLAYER_REGULAR);
        $any = $this->pickAll(['source' => 'any', 'lent' => '1'], PlayerFixture::PLAYER_REGULAR);

        self::assertSame([], array_intersect(self::ids($solved), self::ids($never)));
        self::assertSame($any->totalMatching, $solved->totalMatching + $never->totalMatching);

        // Every puzzle on PLAYER_REGULAR's shelf is solved - "something new from my shelf" is empty
        $newOnShelf = $this->pickAll(['source' => 'mine', 'solved' => 'never'], PlayerFixture::PLAYER_REGULAR);

        self::assertTrue($newOnShelf->isEmpty());
        self::assertSame(0, $newOnShelf->totalMatching);
    }

    public function testTeamSolveWhereIAmOnlyAParticipantCountsAsSolvedWithoutSoloTimes(): void
    {
        // team-002 on PUZZLE_1000_03: PLAYER_REGULAR owns the row, PLAYER_PRIVATE is a participant
        $private = self::byId(
            $this->pickAll(['source' => 'any', 'solved' => 'before'], PlayerFixture::PLAYER_PRIVATE),
            PuzzleFixture::PUZZLE_1000_03,
        );

        self::assertSame(1, $private->mySolveCountAny);
        self::assertSame(0, $private->mySolveCountSolo);
        self::assertNull($private->myFastestSeconds);
        self::assertNull($private->myFirstSeconds);
        self::assertNull($private->myLatestSeconds);
        self::assertNotNull($private->myLastSolvedAt);

        self::assertNotContains(
            PuzzleFixture::PUZZLE_1000_03,
            self::ids($this->pickAll(['source' => 'any', 'solved' => 'never'], PlayerFixture::PLAYER_PRIVATE)),
        );

        // team-001 on PUZZLE_1000_01: PLAYER_REGULAR owns the duo row (no solo time), PLAYER_PRIVATE
        // is a participant AND has her own solo row (TIME_16) - both count as solved
        $regular = self::byId($this->pickAll(['source' => 'any'], PlayerFixture::PLAYER_REGULAR), PuzzleFixture::PUZZLE_1000_01);

        self::assertSame(1, $regular->mySolveCountAny);
        self::assertSame(0, $regular->mySolveCountSolo);
        self::assertNull($regular->myFastestSeconds);

        $privateOnTeamOne = self::byId($this->pickAll(['source' => 'any'], PlayerFixture::PLAYER_PRIVATE), PuzzleFixture::PUZZLE_1000_01);

        self::assertSame(2, $privateOnTeamOne->mySolveCountAny, 'Own solo row + team participation');
        self::assertSame(1, $privateOnTeamOne->mySolveCountSolo);
        self::assertSame(4200, $privateOnTeamOne->myFastestSeconds);
    }

    public function testMyStatsOnARepeatedlySolvedPuzzle(): void
    {
        // PLAYER_REGULAR on PUZZLE_500_02: 36:40 (20 days ago) -> 31:40 (15) -> 28:20 (10)
        $suggestion = self::byId($this->pickAll(['source' => 'mine'], PlayerFixture::PLAYER_REGULAR), PuzzleFixture::PUZZLE_500_02);

        self::assertSame(3, $suggestion->mySolveCountAny);
        self::assertSame(3, $suggestion->mySolveCountSolo);
        self::assertSame(1700, $suggestion->myFastestSeconds);
        self::assertSame(2200, $suggestion->myFirstSeconds);
        self::assertSame(1700, $suggestion->myLatestSeconds);
        self::assertNotNull($suggestion->myLastSolvedAt);
        self::assertEqualsWithDelta(10, (new \DateTimeImmutable())->diff($suggestion->myLastSolvedAt)->days, 1);
        self::assertTrue($suggestion->inMyCollection);
        self::assertSame(PuzzleFixture::PUZZLE_500_02, $suggestion->puzzleId);
        self::assertSame(500, $suggestion->piecesCount);
        self::assertSame(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, $suggestion->manufacturerId);
        self::assertSame('Ravensburger', $suggestion->manufacturerName);
    }

    public function testUntimedRowsCountAsSolvedButNotAsSoloTimes(): void
    {
        // PLAYER_REGULAR on PUZZLE_1000_02: 4500 (4 days ago), 3950 (16 days ago) and an untimed
        // relax row (3 days ago) - the relax row counts as "solved" (like the Unsolved page) but
        // never as a time; the latest *time* is the 4-day-old one, the last *solve* is 3 days old
        $suggestion = self::byId($this->pickAll(['source' => 'mine'], PlayerFixture::PLAYER_REGULAR), PuzzleFixture::PUZZLE_1000_02);

        self::assertSame(3, $suggestion->mySolveCountAny);
        self::assertSame(2, $suggestion->mySolveCountSolo);
        self::assertSame(3950, $suggestion->myFastestSeconds);
        self::assertSame(3950, $suggestion->myFirstSeconds);
        self::assertSame(4500, $suggestion->myLatestSeconds);
        self::assertNotNull($suggestion->myLastSolvedAt);
        self::assertEqualsWithDelta(3, (new \DateTimeImmutable())->diff($suggestion->myLastSolvedAt)->days, 1);
    }

    public function testNeverSolvedPuzzleHasNoPersonalData(): void
    {
        $suggestion = self::byId($this->pickAll(['source' => 'any'], PlayerFixture::PLAYER_REGULAR), PuzzleFixture::PUZZLE_9000);

        self::assertSame(0, $suggestion->mySolveCountAny);
        self::assertSame(0, $suggestion->mySolveCountSolo);
        self::assertNull($suggestion->myFastestSeconds);
        self::assertNull($suggestion->myFirstSeconds);
        self::assertNull($suggestion->myLatestSeconds);
        self::assertNull($suggestion->myLastSolvedAt);
        self::assertFalse($suggestion->inMyCollection);
        self::assertFalse($suggestion->isBorrowed);
        self::assertFalse($suggestion->isLentOut);
    }

    public function testCommunityStatisticsAreHydrated(): void
    {
        // PUZZLE_500_01: five solo solves 25-50 min, avg ~36 min (plus competition rows)
        $suggestion = self::byId($this->pickAll(['source' => 'any'], null), PuzzleFixture::PUZZLE_500_01);

        self::assertGreaterThanOrEqual(5, $suggestion->communitySolvedCountSolo);
        self::assertNotNull($suggestion->communityAverageTimeSolo);
        self::assertGreaterThan(1500, $suggestion->communityAverageTimeSolo);
        self::assertLessThan(3000, $suggestion->communityAverageTimeSolo);
    }

    public function testPiecesFilterSupportsExactRangesAndOpenEnds(): void
    {
        $exact = $this->pickAll(['pieces' => ['500']], null);

        foreach ([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03, PuzzleFixture::PUZZLE_500_04, PuzzleFixture::PUZZLE_500_05] as $puzzleId) {
            self::assertContains($puzzleId, self::ids($exact));
        }

        foreach ($exact->suggestions as $suggestion) {
            self::assertSame(500, $suggestion->piecesCount);
        }

        self::assertEqualsCanonicalizing(
            [PuzzleFixture::PUZZLE_300],
            self::ids($this->pickAll(['pieces' => ['-499']], null)),
        );

        self::assertEqualsCanonicalizing([
            PuzzleFixture::PUZZLE_1500_01,
            PuzzleFixture::PUZZLE_1500_02,
            PuzzleFixture::PUZZLE_2000,
            PuzzleFixture::PUZZLE_3000,
            PuzzleFixture::PUZZLE_4000,
            PuzzleFixture::PUZZLE_5000,
            PuzzleFixture::PUZZLE_6000,
            PuzzleFixture::PUZZLE_9000,
        ], self::ids($this->pickAll(['pieces' => ['1500-']], null)));

        self::assertEqualsCanonicalizing([
            PuzzleFixture::PUZZLE_1500_01,
            PuzzleFixture::PUZZLE_1500_02,
            PuzzleFixture::PUZZLE_2000,
            PuzzleFixture::PUZZLE_3000,
        ], self::ids($this->pickAll(['pieces' => ['1200-3000']], null)));

        // Several ranges are OR-ed
        $thousand = $this->pickAll(['pieces' => ['1000']], null);
        $both = $this->pickAll(['pieces' => ['500', '1000']], null);
        $three = $this->pickAll(['pieces' => ['500', '1000', '-300']], null);

        self::assertSame($exact->totalMatching + $thousand->totalMatching, $both->totalMatching);
        self::assertSame($both->totalMatching + 1, $three->totalMatching);
        self::assertContains(PuzzleFixture::PUZZLE_300, self::ids($three));
    }

    public function testBrandFilterAcceptsSeveralBrands(): void
    {
        $trefl = $this->pickAll(['brand' => [ManufacturerFixture::MANUFACTURER_TREFL]], null);

        self::assertEqualsCanonicalizing([
            PuzzleFixture::PUZZLE_500_04,
            PuzzleFixture::PUZZLE_500_05,
            PuzzleFixture::PUZZLE_1000_02,
            PuzzleFixture::PUZZLE_1000_04,
            PuzzleFixture::PUZZLE_1500_02,
            PuzzleFixture::PUZZLE_3000,
            PuzzleFixture::PUZZLE_6000,
        ], self::ids($trefl));

        foreach ($trefl->suggestions as $suggestion) {
            self::assertSame('Trefl', $suggestion->manufacturerName);
        }

        $ravensburger = $this->pickAll(['brand' => [ManufacturerFixture::MANUFACTURER_RAVENSBURGER]], null);
        $both = $this->pickAll(['brand' => [ManufacturerFixture::MANUFACTURER_TREFL, ManufacturerFixture::MANUFACTURER_RAVENSBURGER]], null);

        self::assertSame($trefl->totalMatching + $ravensburger->totalMatching, $both->totalMatching);
        self::assertSame([], array_intersect(self::ids($trefl), self::ids($ravensburger)));

        // Combined with pieces
        self::assertEqualsCanonicalizing(
            [PuzzleFixture::PUZZLE_500_04, PuzzleFixture::PUZZLE_500_05],
            self::ids($this->pickAll(['brand' => [ManufacturerFixture::MANUFACTURER_TREFL], 'pieces' => ['500']], null)),
        );
    }

    public function testGuestGetsTheWholePoolWithoutPersonalData(): void
    {
        $guest = $this->pickAll(['source' => 'mine', 'solved' => 'never', 'lent' => '1'], null, isAuthenticated: false);
        $approvedCount = $this->database->fetchOne(
            'SELECT count(*) FROM puzzle WHERE approved = true AND (hide_until IS NULL OR hide_until < now())',
        );
        assert(is_int($approvedCount));

        self::assertSame($approvedCount, $guest->totalMatching, 'Personal filters are forced off for guests');
        self::assertNotContains(PuzzleFixture::PUZZLE_UNAPPROVED, self::ids($guest));

        foreach ($guest->suggestions as $suggestion) {
            self::assertSame(0, $suggestion->mySolveCountAny);
            self::assertSame(0, $suggestion->mySolveCountSolo);
            self::assertNull($suggestion->myFastestSeconds);
            self::assertNull($suggestion->myFirstSeconds);
            self::assertNull($suggestion->myLatestSeconds);
            self::assertNull($suggestion->myLastSolvedAt);
            self::assertFalse($suggestion->inMyCollection);
            self::assertFalse($suggestion->isBorrowed);
            self::assertFalse($suggestion->isLentOut);
        }

        // The seeded order does not depend on who is asking
        $seeded = $this->criteria(['seed' => 'abcd1234'], isAuthenticated: false);

        self::assertSame(
            self::ids($this->getPuzzlePickerSuggestions->pick($seeded, null, 6)),
            self::ids($this->getPuzzlePickerSuggestions->pick($seeded, null, 6)),
        );
    }

    public function testLimitAndTotalMatching(): void
    {
        $pick = $this->getPuzzlePickerSuggestions->pick($this->criteria(['source' => 'any', 'seed' => 'abcd1234']), PlayerFixture::PLAYER_REGULAR, 6);

        self::assertCount(6, $pick->suggestions);
        self::assertGreaterThan(6, $pick->totalMatching);

        $none = $this->getPuzzlePickerSuggestions->pick($this->criteria(['pieces' => ['77777']]), PlayerFixture::PLAYER_REGULAR, 6);

        self::assertTrue($none->isEmpty());
        self::assertSame(0, $none->totalMatching);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function criteria(array $query, bool $isAuthenticated = true): PuzzlePickerCriteria
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request($query), $isAuthenticated);

        return $criteria->seed === null ? $criteria->withSeed('abcd1234') : $criteria;
    }

    /**
     * A null player is a guest (source forced to "any") unless said otherwise.
     *
     * @param array<string, mixed> $query
     */
    private function pickAll(array $query, null|string $playerId, null|bool $isAuthenticated = null): PuzzlePickerPick
    {
        return $this->getPuzzlePickerSuggestions->pick($this->criteria($query, $isAuthenticated ?? $playerId !== null), $playerId, 1000);
    }

    /**
     * @return list<string>
     */
    private static function ids(PuzzlePickerPick $pick): array
    {
        return array_map(static fn (PuzzlePickerSuggestion $suggestion): string => $suggestion->puzzleId, $pick->suggestions);
    }

    private static function byId(PuzzlePickerPick $pick, string $puzzleId): PuzzlePickerSuggestion
    {
        foreach ($pick->suggestions as $suggestion) {
            if ($suggestion->puzzleId === $puzzleId) {
                return $suggestion;
            }
        }

        self::fail("Puzzle {$puzzleId} not in the pick");
    }
}
