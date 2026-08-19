<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Query\GetPuzzlePickerSuggestions;
use SpeedPuzzling\Web\Results\PuzzlePickerPick;
use SpeedPuzzling\Web\Results\PuzzlePickerSuggestion;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleIntelligenceFixture;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\MetricConfidence;
use SpeedPuzzling\Web\Value\PuzzlePickerCriteria;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class GetPuzzlePickerSuggestionsTest extends KernelTestCase
{
    private GetPuzzlePickerSuggestions $getPuzzlePickerSuggestions;

    private GetPlayerPredictions $getPlayerPredictions;

    private PuzzleIntelligenceRecalculator $recalculator;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->getPuzzlePickerSuggestions = $container->get(GetPuzzlePickerSuggestions::class);
        $this->getPlayerPredictions = $container->get(GetPlayerPredictions::class);
        $this->recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
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


    // ---------------------------------------------------------------------------------------------
    // Insights layer (members): difficulty tiers, gap vs. my prediction, gap orders, time budget
    // ---------------------------------------------------------------------------------------------

    public function testDifficultyIsHydratedOnEveryCardButPredictionFieldsOnlyWhenComputedPreLimit(): void
    {
        $this->recalculator->recalculate();
        $any = $this->pickAll(['source' => 'any'], PlayerFixture::PLAYER_PRIVATE);

        // Scored puzzle: tier + confidence; unscored: tier null; no difficulty row at all: both null
        $scored = self::byId($any, PuzzleFixture::PUZZLE_500_01);
        self::assertSame(DifficultyTier::Average, $scored->difficultyTier);
        self::assertSame(MetricConfidence::Low, $scored->difficultyConfidence);
        self::assertTrue($scored->hasDifficulty());

        $insufficient = self::byId($any, PuzzleFixture::PUZZLE_300);
        self::assertNull($insufficient->difficultyTier);
        self::assertSame(MetricConfidence::Insufficient, $insufficient->difficultyConfidence);
        self::assertFalse($insufficient->hasDifficulty());

        $noRow = self::byId($any, PuzzleFixture::PUZZLE_9000);
        self::assertNull($noRow->difficultyTier);
        self::assertNull($noRow->difficultyConfidence);
        self::assertFalse($noRow->hasDifficulty());

        // Without an insights filter nothing is computed before the LIMIT (PR 1 query shape)
        foreach ($any->suggestions as $suggestion) {
            self::assertNull($suggestion->predictedSeconds);
            self::assertNull($suggestion->gapSeconds);
        }
    }

    public function testDifficultyTierFilterKeepsOnlyScoredPuzzlesOfThoseTiers(): void
    {
        $this->recalculator->recalculate();
        // The five puzzles with a difficulty score are all tier 3 (Average); PLAYER_PRIVATE has nothing lent out
        $scored = [
            PuzzleFixture::PUZZLE_500_01,
            PuzzleFixture::PUZZLE_500_02,
            PuzzleFixture::PUZZLE_500_03,
            PuzzleIntelligenceFixture::INTEL_PUZZLE_A,
            PuzzleIntelligenceFixture::INTEL_PUZZLE_B,
        ];

        $average = $this->pickAll(['source' => 'any', 'difficulty' => ['3']], PlayerFixture::PLAYER_PRIVATE, member: true);
        self::assertEqualsCanonicalizing($scored, self::ids($average));
        self::assertSame(5, $average->totalMatching);

        foreach ($average->suggestions as $suggestion) {
            self::assertSame(DifficultyTier::Average, $suggestion->difficultyTier);
            self::assertTrue($suggestion->hasDifficulty());
        }

        self::assertTrue($this->pickAll(['source' => 'any', 'difficulty' => ['1']], PlayerFixture::PLAYER_PRIVATE, member: true)->isEmpty());
        self::assertEqualsCanonicalizing($scored, self::ids($this->pickAll(['source' => 'any', 'difficulty' => ['1', '3', '6']], PlayerFixture::PLAYER_PRIVATE, member: true)));

        // Combines with the free filters (all five scored puzzles are Ravensburger, 500 pieces, solved by PLAYER_PRIVATE)
        self::assertEqualsCanonicalizing(
            $scored,
            self::ids($this->pickAll(['source' => 'any', 'difficulty' => ['3'], 'brand' => [ManufacturerFixture::MANUFACTURER_RAVENSBURGER], 'solved' => 'before', 'pieces' => ['500']], PlayerFixture::PLAYER_PRIVATE, member: true)),
        );
        self::assertTrue($this->pickAll(['source' => 'any', 'difficulty' => ['3'], 'brand' => [ManufacturerFixture::MANUFACTURER_TREFL]], PlayerFixture::PLAYER_PRIVATE, member: true)->isEmpty());
        self::assertTrue($this->pickAll(['source' => 'any', 'difficulty' => ['3'], 'solved' => 'never'], PlayerFixture::PLAYER_PRIVATE, member: true)->isEmpty());

        // Non-members: the tier filter is stripped by the criteria, the pool stays whole
        $everything = $this->pickAll(['source' => 'any'], PlayerFixture::PLAYER_PRIVATE);
        self::assertSame($everything->totalMatching, $this->pickAll(['source' => 'any', 'difficulty' => ['3']], PlayerFixture::PLAYER_PRIVATE)->totalMatching);
    }

    public function testGapSlowerAndFasterCompareMyFastestSoloTimeWithMyPrediction(): void
    {
        $this->recalculator->recalculate();
        $playerId = PlayerFixture::PLAYER_REGULAR;
        $predictions = $this->getPlayerPredictions->forAllSolvedPuzzles($playerId);
        $all = $this->pickAll(['source' => 'any', 'lent' => '1'], $playerId, member: true);

        $expectedSlower = [];
        $expectedFaster = [];
        $expectedGaps = [];

        foreach ($all->suggestions as $suggestion) {
            if ($suggestion->mySolveCountSolo === 0 || isset($predictions[$suggestion->puzzleId]) === false) {
                continue;
            }

            $gap = (int) $suggestion->myFastestSeconds - $predictions[$suggestion->puzzleId]->predictedSeconds;
            $expectedGaps[$suggestion->puzzleId] = $gap;

            if ($gap >= 60) {
                $expectedSlower[] = $suggestion->puzzleId;
            }

            if ($gap <= -60) {
                $expectedFaster[] = $suggestion->puzzleId;
            }
        }

        // PLAYER_REGULAR improves on PUZZLE_500_02 (2200 -> 1900 -> 1700): predicted below the best -> "slower"
        self::assertGreaterThan(0, $expectedGaps[PuzzleFixture::PUZZLE_500_02]);
        self::assertContains(PuzzleFixture::PUZZLE_500_02, $expectedSlower);
        self::assertNotSame([], $expectedFaster, 'The fixture needs at least one puzzle where the player beat the prediction');

        $slower = $this->pickAll(['source' => 'any', 'lent' => '1', 'gap' => 'slower'], $playerId, member: true);
        self::assertEqualsCanonicalizing($expectedSlower, self::ids($slower));
        self::assertSame(count($expectedSlower), $slower->totalMatching);

        foreach ($slower->suggestions as $suggestion) {
            self::assertSame($expectedGaps[$suggestion->puzzleId], $suggestion->gapSeconds);
            self::assertSame($predictions[$suggestion->puzzleId]->predictedSeconds, $suggestion->predictedSeconds);
            self::assertGreaterThanOrEqual(60, $suggestion->gapSeconds);
        }

        $faster = $this->pickAll(['source' => 'any', 'lent' => '1', 'gap' => 'faster'], $playerId, member: true);
        self::assertEqualsCanonicalizing($expectedFaster, self::ids($faster));

        foreach ($faster->suggestions as $suggestion) {
            self::assertSame($expectedGaps[$suggestion->puzzleId], $suggestion->gapSeconds);
            self::assertLessThanOrEqual(-60, $suggestion->gapSeconds);
        }

        self::assertSame([], array_intersect(self::ids($slower), self::ids($faster)));

        // "by at least N min" tightens the threshold
        self::assertNotSame([], $expectedGaps);
        $gapMin = 1 + intdiv(max($expectedGaps), 60);
        self::assertTrue($this->pickAll(['source' => 'any', 'lent' => '1', 'gap' => 'slower', 'gap_min' => (string) ($gapMin + 60)], $playerId, member: true)->isEmpty());
        $tight = $this->pickAll(['source' => 'any', 'lent' => '1', 'gap' => 'slower', 'gap_min' => '2'], $playerId, member: true);
        self::assertEqualsCanonicalizing(
            array_keys(array_filter($expectedGaps, static fn (int $gap): bool => $gap >= 120)),
            self::ids($tight),
        );

        // Puzzles without a solo time never match a gap filter, whatever the prediction
        foreach ([...$slower->suggestions, ...$faster->suggestions] as $suggestion) {
            self::assertGreaterThan(0, $suggestion->mySolveCountSolo);
        }

        // Non-members / opted-out: the gap filter is stripped, the pool stays whole
        self::assertSame($all->totalMatching, $this->pickAll(['source' => 'any', 'lent' => '1', 'gap' => 'slower'], $playerId)->totalMatching);
        self::assertSame($all->totalMatching, $this->pickAll(['source' => 'any', 'lent' => '1', 'gap' => 'slower'], $playerId, member: true, predictions: false)->totalMatching);
    }

    public function testGapOrdersAreDeterministicWithTheSeedAsTieBreaker(): void
    {
        $this->recalculator->recalculate();
        $playerId = PlayerFixture::PLAYER_REGULAR;
        $seed = 'abcd1234';
        $predictions = $this->getPlayerPredictions->forAllSolvedPuzzles($playerId);
        $all = $this->pickAll(['source' => 'any', 'lent' => '1'], $playerId, member: true);

        $gaps = [];

        foreach ($all->suggestions as $suggestion) {
            $gaps[$suggestion->puzzleId] = $suggestion->mySolveCountSolo > 0 && isset($predictions[$suggestion->puzzleId])
                ? (int) $suggestion->myFastestSeconds - $predictions[$suggestion->puzzleId]->predictedSeconds
                : null;
        }

        $knownGaps = array_filter($gaps, static fn (null|int $gap): bool => $gap !== null);
        self::assertNotSame([], $knownGaps);
        self::assertGreaterThan(count($knownGaps), count($gaps), 'Some puzzles have no gap and must sort last');

        $expectedSlowerFirst = array_keys($gaps);
        usort($expectedSlowerFirst, static function (string $a, string $b) use ($gaps, $seed): int {
            if ($gaps[$a] === null || $gaps[$b] === null) {
                return ($gaps[$a] === null ? 1 : 0) <=> ($gaps[$b] === null ? 1 : 0) ?: strcmp(md5($seed . $a), md5($seed . $b));
            }

            return $gaps[$b] <=> $gaps[$a] ?: strcmp(md5($seed . $a), md5($seed . $b));
        });

        $slowerFirst = $this->getPuzzlePickerSuggestions->pick($this->memberCriteria(['source' => 'any', 'lent' => '1', 'order' => 'gap_slower', 'seed' => $seed]), $playerId, 1000);
        self::assertSame($expectedSlowerFirst, self::ids($slowerFirst), 'gap DESC NULLS LAST, then the seeded shuffle');
        self::assertSame($all->totalMatching, $slowerFirst->totalMatching, 'An order never filters');
        self::assertSame($gaps[$expectedSlowerFirst[0]], $slowerFirst->suggestions[0]->gapSeconds);
        self::assertSame(max($knownGaps), $slowerFirst->suggestions[0]->gapSeconds);
        self::assertNull($slowerFirst->suggestions[count($gaps) - 1]->gapSeconds, 'Puzzles without a gap come last');

        $expectedFasterFirst = array_keys($gaps);
        usort($expectedFasterFirst, static function (string $a, string $b) use ($gaps, $seed): int {
            if ($gaps[$a] === null || $gaps[$b] === null) {
                return ($gaps[$a] === null ? 1 : 0) <=> ($gaps[$b] === null ? 1 : 0) ?: strcmp(md5($seed . $a), md5($seed . $b));
            }

            return $gaps[$a] <=> $gaps[$b] ?: strcmp(md5($seed . $a), md5($seed . $b));
        });

        $fasterFirst = $this->getPuzzlePickerSuggestions->pick($this->memberCriteria(['source' => 'any', 'lent' => '1', 'order' => 'gap_faster', 'seed' => $seed]), $playerId, 1000);
        self::assertSame($expectedFasterFirst, self::ids($fasterFirst), 'gap ASC NULLS LAST, then the seeded shuffle');
        self::assertSame(min($knownGaps), $fasterFirst->suggestions[0]->gapSeconds);

        // Paging windows of the same order, and a re-run gives the same answer
        $firstPage = $this->getPuzzlePickerSuggestions->pick($this->memberCriteria(['source' => 'any', 'lent' => '1', 'order' => 'gap_slower', 'seed' => $seed]), $playerId, 6);
        $secondPage = $this->getPuzzlePickerSuggestions->pick($this->memberCriteria(['source' => 'any', 'lent' => '1', 'order' => 'gap_slower', 'seed' => $seed]), $playerId, 6, 6);
        self::assertSame(array_slice($expectedSlowerFirst, 0, 12), [...self::ids($firstPage), ...self::ids($secondPage)]);

        // Order + gap filter = "Beat my record": only slower puzzles, largest gap first
        $beat = $this->getPuzzlePickerSuggestions->pick($this->memberCriteria(['source' => 'any', 'lent' => '1', 'solved' => 'before', 'gap' => 'slower', 'order' => 'gap_slower', 'seed' => $seed]), $playerId, 1000);
        self::assertSame(
            array_values(array_filter($expectedSlowerFirst, static fn (string $id): bool => $gaps[$id] !== null && $gaps[$id] >= 60)),
            self::ids($beat),
        );

        // Not allowed -> plain seeded shuffle
        $stripped = $this->getPuzzlePickerSuggestions->pick($this->criteria(['source' => 'any', 'lent' => '1', 'order' => 'gap_slower', 'seed' => $seed]), $playerId, 1000);
        self::assertSame(self::ids($this->getPuzzlePickerSuggestions->pick($this->criteria(['source' => 'any', 'lent' => '1', 'seed' => $seed]), $playerId, 1000)), self::ids($stripped));
    }

    public function testTimeBudgetUsesMyPredictionForMembersAndTheCommunityAverageOtherwise(): void
    {
        $this->recalculator->recalculate();
        $playerId = PlayerFixture::PLAYER_REGULAR;
        $all = $this->pickAll(['source' => 'any', 'lent' => '1'], $playerId, member: true);
        $predictions = $this->getPlayerPredictions->forPuzzles($playerId, self::ids($all));
        $budgetMinutes = 32;

        // Members with predictions: personal where solved, statistical where baseline x difficulty exist
        $expectedPersonal = array_keys(array_filter(
            $predictions,
            static fn ($prediction): bool => $prediction->predictedSeconds <= $budgetMinutes * 60,
        ));
        self::assertNotSame([], $expectedPersonal);
        self::assertGreaterThan(count($expectedPersonal), count($predictions), 'The budget must actually cut something');

        $personal = $this->pickAll(['source' => 'any', 'lent' => '1', 'predicted_max' => (string) $budgetMinutes], $playerId, member: true);
        self::assertEqualsCanonicalizing($expectedPersonal, self::ids($personal));

        foreach ($personal->suggestions as $suggestion) {
            self::assertSame($predictions[$suggestion->puzzleId]->predictedSeconds, $suggestion->predictedSeconds);
            self::assertLessThanOrEqual($budgetMinutes * 60, $suggestion->predictedSeconds);
        }

        // Everybody else: community solo average
        /** @var list<string> $expectedCommunity */
        $expectedCommunity = $this->database->fetchFirstColumn(
            'SELECT puzzle_id FROM puzzle_statistics WHERE average_time_solo <= :seconds',
            ['seconds' => $budgetMinutes * 60],
        );
        $expectedCommunity = array_values(array_intersect($expectedCommunity, self::ids($all)));
        self::assertNotSame([], $expectedCommunity);
        self::assertNotEqualsCanonicalizing($expectedPersonal, $expectedCommunity, 'The two engines must give different answers for the test to mean anything');

        $nonMember = $this->pickAll(['source' => 'any', 'lent' => '1', 'predicted_max' => (string) $budgetMinutes], $playerId);
        self::assertEqualsCanonicalizing($expectedCommunity, self::ids($nonMember));

        foreach ($nonMember->suggestions as $suggestion) {
            self::assertNotNull($suggestion->communityAverageTimeSolo);
            self::assertLessThanOrEqual($budgetMinutes * 60, $suggestion->communityAverageTimeSolo);
            self::assertNull($suggestion->predictedSeconds, 'Community engine computes no prediction');
        }

        $optedOut = $this->pickAll(['source' => 'any', 'lent' => '1', 'predicted_max' => (string) $budgetMinutes], $playerId, member: true, predictions: false);
        self::assertEqualsCanonicalizing($expectedCommunity, self::ids($optedOut));

        $guest = $this->pickAll(['predicted_max' => (string) $budgetMinutes], null);
        self::assertEqualsCanonicalizing(
            $this->database->fetchFirstColumn('SELECT ps.puzzle_id FROM puzzle_statistics ps JOIN puzzle p ON p.id = ps.puzzle_id WHERE p.approved = true AND ps.average_time_solo <= :seconds', ['seconds' => $budgetMinutes * 60]),
            self::ids($guest),
        );
    }

    public function testInsightsFiltersAreIgnoredForGuests(): void
    {
        $this->recalculator->recalculate();
        $guest = $this->pickAll(['gap' => 'slower', 'order' => 'gap_slower', 'difficulty' => ['3']], null, isAuthenticated: false);
        $approvedCount = $this->database->fetchOne(
            'SELECT count(*) FROM puzzle WHERE approved = true AND (hide_until IS NULL OR hide_until < now())',
        );

        self::assertSame($approvedCount, $guest->totalMatching);

        foreach ($guest->suggestions as $suggestion) {
            self::assertNull($suggestion->predictedSeconds);
            self::assertNull($suggestion->gapSeconds);
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private function criteria(array $query, bool $isAuthenticated = true, bool $member = false, bool $predictions = true): PuzzlePickerCriteria
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request($query), $isAuthenticated, insightsAllowed: $member, predictionsAllowed: $member && $predictions);

        return $criteria->seed === null ? $criteria->withSeed('abcd1234') : $criteria;
    }

    /**
     * A member who has not opted out of predictions.
     *
     * @param array<string, mixed> $query
     */
    private function memberCriteria(array $query): PuzzlePickerCriteria
    {
        return $this->criteria($query, member: true);
    }

    /**
     * A null player is a guest (source forced to "any") unless said otherwise.
     *
     * @param array<string, mixed> $query
     */
    private function pickAll(array $query, null|string $playerId, null|bool $isAuthenticated = null, bool $member = false, bool $predictions = true): PuzzlePickerPick
    {
        return $this->getPuzzlePickerSuggestions->pick($this->criteria($query, $isAuthenticated ?? $playerId !== null, $member, $predictions), $playerId, 1000);
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
