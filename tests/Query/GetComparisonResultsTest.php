<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetComparisonResults;
use SpeedPuzzling\Web\Results\ComparisonTimeRow;
use SpeedPuzzling\Web\Tests\ComparisonSeeding;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\ComparisonCriteria;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetComparisonResultsTest extends KernelTestCase
{
    use ComparisonSeeding;

    private GetComparisonResults $query;
    private DateTimeImmutable $now;
    private string $anna;
    private string $ben;
    private string $cleo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetComparisonResults::class);
        $this->now = self::getContainer()->get(ClockInterface::class)->now();
        $this->anna = $this->seedPlayer('Anna');
        $this->ben = $this->seedPlayer('Ben');
        $this->cleo = $this->seedPlayer('Cleo');
    }

    public function testBestTimeFirstTryAndAttemptsPerSubjectAndPuzzle(): void
    {
        $puzzle = $this->seedPuzzle(500);
        $firstTry = $this->seedTime($this->anna, $puzzle, 2400, $this->daysAgo(30), firstAttempt: true);
        $best = $this->seedTime($this->anna, $puzzle, 1800, $this->daysAgo(10));
        $this->seedTime($this->anna, $puzzle, 2000, $this->daysAgo(5));
        $this->seedTime($this->ben, $puzzle, 2100, $this->daysAgo(3));

        $rows = $this->rows([$this->anna, $this->ben]);

        $anna = $rows[$this->key($this->anna, $puzzle)];
        self::assertSame(3, $anna->attempts);
        self::assertSame(1800, $anna->bestSeconds);
        self::assertSame($best, $anna->bestTimeId);
        self::assertSame($this->daysAgo(10)->format('Y-m-d H:i:s'), $anna->bestDay->format('Y-m-d H:i:s'));
        self::assertSame(2400, $anna->firstTrySeconds);
        self::assertSame($firstTry, $anna->firstTryTimeId);
        self::assertSame($this->daysAgo(30)->format('Y-m-d'), $anna->firstTryDay?->format('Y-m-d'));
        self::assertSame(500, $anna->piecesCount);
        self::assertTrue($anna->subject->equals(ComparisonSubjectRef::player($this->anna)));

        $ben = $rows[$this->key($this->ben, $puzzle)];
        self::assertSame(1, $ben->attempts);
        self::assertNull($ben->firstTrySeconds, 'No time flagged as a first try');
        self::assertNull($ben->firstTryTimeId);
        self::assertNull($ben->firstTryDay);
    }

    public function testSuspiciousAndTimelessTimesDoNotCount(): void
    {
        $puzzle = $this->seedPuzzle(500);
        $this->seedTime($this->anna, $puzzle, 900, $this->daysAgo(4), suspicious: true);
        $this->seedTime($this->anna, $puzzle, null, $this->daysAgo(3));
        $valid = $this->seedTime($this->anna, $puzzle, 1500, $this->daysAgo(2));
        $this->seedTime($this->ben, $puzzle, 1600, $this->daysAgo(2));
        // Ben's only time on the second puzzle is suspicious - the puzzle is solved by Anna alone
        $second = $this->seedPuzzle(500);
        $this->seedTime($this->anna, $second, 1500, $this->daysAgo(2));
        $this->seedTime($this->ben, $second, 1000, $this->daysAgo(2), suspicious: true);

        $rows = $this->rows([$this->anna, $this->ben]);

        $anna = $rows[$this->key($this->anna, $puzzle)];
        self::assertSame(1, $anna->attempts);
        self::assertSame($valid, $anna->bestTimeId);
        self::assertArrayNotHasKey($this->key($this->anna, $second), $rows, 'Solved by both = Ben must have a valid time');
    }

    public function testEqualBestTimesPickTheEarliestAndLegacyDuplicateFirstTriesTheEarliest(): void
    {
        $puzzle = $this->seedPuzzle(500);
        $sameDay = $this->daysAgo(8);
        $this->seedTime($this->anna, $puzzle, 1700, $this->daysAgo(2));
        $earliestBest = $this->seedTime($this->anna, $puzzle, 1700, $this->daysAgo(6));
        // Two times flagged as a first try on the same finish day: the one tracked first counts
        $this->seedTime($this->anna, $puzzle, 2500, $sameDay, firstAttempt: true, trackedAt: $sameDay->modify('+2 hours'));
        $earliestFirstTry = $this->seedTime($this->anna, $puzzle, 2600, $sameDay, firstAttempt: true, trackedAt: $sameDay->modify('+1 hour'));
        $this->seedTime($this->ben, $puzzle, 1600, $this->daysAgo(2));

        $anna = $this->rows([$this->anna, $this->ben])[$this->key($this->anna, $puzzle)];

        self::assertSame($earliestBest, $anna->bestTimeId);
        self::assertSame($earliestFirstTry, $anna->firstTryTimeId);
        self::assertSame(2600, $anna->firstTrySeconds);
    }

    public function testDayFallsBackToTrackedAt(): void
    {
        $puzzle = $this->seedPuzzle(500);
        $this->seedTime($this->anna, $puzzle, 1700, $this->daysAgo(400), trackedAt: $this->daysAgo(20), finishedAtIsNull: true);
        $this->seedTime($this->ben, $puzzle, 1600, $this->daysAgo(2));

        $anna = $this->rows([$this->anna, $this->ben])[$this->key($this->anna, $puzzle)];

        self::assertSame($this->daysAgo(20)->format('Y-m-d'), $anna->bestDay->format('Y-m-d'));
    }

    public function testFirstTriesFilterAppliesBeforeAggregating(): void
    {
        $puzzle = $this->seedPuzzle(500);
        $annaFirst = $this->seedTime($this->anna, $puzzle, 2400, $this->daysAgo(30), firstAttempt: true);
        $this->seedTime($this->anna, $puzzle, 1200, $this->daysAgo(10));
        $this->seedTime($this->ben, $puzzle, 2000, $this->daysAgo(20), firstAttempt: true);
        // Cleo never had a first try on it: with first tries only she has nothing here
        $this->seedTime($this->cleo, $puzzle, 1000, $this->daysAgo(20));

        $criteria = ComparisonCriteria::fromUserInput(subjectCount: 3, isMember: true, show: 'all', times: 'first');
        $rows = $this->rows([$this->anna, $this->ben, $this->cleo], $criteria);

        $anna = $rows[$this->key($this->anna, $puzzle)];
        self::assertSame(1, $anna->attempts, 'Only first tries are aggregated');
        self::assertSame(2400, $anna->bestSeconds, '"Best" is the best within the filter');
        self::assertSame($annaFirst, $anna->bestTimeId);
        self::assertSame($annaFirst, $anna->firstTryTimeId);
        self::assertArrayNotHasKey($this->key($this->cleo, $puzzle), $rows);
    }

    public function testPeriodFilterAppliesBeforeAggregating(): void
    {
        $puzzle = $this->seedPuzzle(500);
        $this->seedTime($this->anna, $puzzle, 1000, $this->daysAgo(200));
        $recent = $this->seedTime($this->anna, $puzzle, 1500, $this->daysAgo(20));
        $this->seedTime($this->ben, $puzzle, 1600, $this->daysAgo(10));
        // Ben solved this one only long ago - outside the period it is Anna's alone
        $old = $this->seedPuzzle(1000);
        $this->seedTime($this->anna, $old, 3000, $this->daysAgo(10));
        $this->seedTime($this->ben, $old, 3100, $this->daysAgo(150));

        $criteria = ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: false, period: '3m');
        $rows = $this->rows([$this->anna, $this->ben], $criteria);

        $anna = $rows[$this->key($this->anna, $puzzle)];
        self::assertSame(1500, $anna->bestSeconds);
        self::assertSame($recent, $anna->bestTimeId);
        self::assertSame(1, $anna->attempts);
        self::assertArrayNotHasKey($this->key($this->anna, $old), $rows);

        $custom = ComparisonCriteria::fromUserInput(
            subjectCount: 2,
            isMember: true,
            period: 'custom',
            from: $this->daysAgo(250)->format('Y-m-d'),
            to: $this->daysAgo(100)->format('Y-m-d'),
        );
        $rows = $this->rows([$this->anna, $this->ben], $custom);

        self::assertSame([], $rows, 'Between 250 and 100 days ago Anna and Ben share nothing');
    }

    public function testShowFilterKeepsPuzzlesSolvedByEnoughSubjects(): void
    {
        $byAll = $this->seedPuzzle(500);
        $byTwo = $this->seedPuzzle(500);
        $byOne = $this->seedPuzzle(500);

        foreach ([$this->anna, $this->ben, $this->cleo] as $player) {
            $this->seedTime($player, $byAll, 1500, $this->daysAgo(5));
        }

        $this->seedTime($this->anna, $byTwo, 1500, $this->daysAgo(5));
        $this->seedTime($this->cleo, $byTwo, 1500, $this->daysAgo(5));
        $this->seedTime($this->ben, $byOne, 1500, $this->daysAgo(5));

        $players = [$this->anna, $this->ben, $this->cleo];

        self::assertEqualsCanonicalizing([$byAll], $this->puzzles($players, ComparisonCriteria::fromUserInput(subjectCount: 3, isMember: false, show: 'everyone')));
        self::assertEqualsCanonicalizing([$byAll, $byTwo], $this->puzzles($players, ComparisonCriteria::fromUserInput(subjectCount: 3, isMember: false)));
        self::assertEqualsCanonicalizing([$byAll, $byTwo, $byOne], $this->puzzles($players, ComparisonCriteria::fromUserInput(subjectCount: 3, isMember: false, show: 'all')));
    }

    public function testPuzzleFiltersAndHiddenPuzzles(): void
    {
        $brand = $this->seedManufacturer('Comparison Brand');
        $other = $this->seedManufacturer('Other Brand');
        $small = $this->seedPuzzle(300, manufacturerId: $brand);
        $medium = $this->seedPuzzle(500, manufacturerId: $brand);
        $mediumOther = $this->seedPuzzle(500, manufacturerId: $other);
        $large = $this->seedPuzzle(1000, manufacturerId: $brand);
        $hidden = $this->seedPuzzle(500, manufacturerId: $brand, hideUntil: $this->now->modify('+30 days'));
        $this->seedDifficulty($medium, 3);
        $this->seedDifficulty($large, 5);
        // $small and $mediumOther are not rated yet

        foreach ([$small, $medium, $mediumOther, $large, $hidden] as $puzzle) {
            $this->seedTime($this->anna, $puzzle, 1500, $this->daysAgo(5));
            $this->seedTime($this->ben, $puzzle, 1600, $this->daysAgo(5));
        }

        $players = [$this->anna, $this->ben];

        self::assertEqualsCanonicalizing([$small, $medium, $mediumOther, $large], $this->puzzles($players, ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: false)), 'A puzzle hidden until later is never compared');
        self::assertEqualsCanonicalizing([$medium, $mediumOther], $this->puzzles($players, ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: false, pieces: '500')));
        self::assertEqualsCanonicalizing([$medium, $large], $this->puzzles($players, ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: true, pieces: '400-', brands: [$brand])));
        self::assertEqualsCanonicalizing([$large], $this->puzzles($players, ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: true, difficulty: ['5'])));
        self::assertEqualsCanonicalizing([$small, $mediumOther, $large], $this->puzzles($players, ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: true, difficulty: ['0', '5'])), 'Unrated = no tier computed');
    }

    public function testDifficultyAndNamesAreSelectedOnlyWhenNeeded(): void
    {
        $puzzle = $this->seedPuzzle(500, name: 'Night Owls');
        $this->seedDifficulty($puzzle, 4);
        $this->seedTime($this->anna, $puzzle, 1500, $this->daysAgo(5));
        $this->seedTime($this->ben, $puzzle, 1600, $this->daysAgo(5));

        $plain = $this->rows([$this->anna, $this->ben])[$this->key($this->anna, $puzzle)];
        self::assertNull($plain->difficultyTier);
        self::assertNull($plain->puzzleName);

        $bySort = $this->rows([$this->anna, $this->ben], ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: true, sort: 'difficulty'))[$this->key($this->anna, $puzzle)];
        self::assertSame(4, $bySort->difficultyTier);

        $named = $this->query->forSubjects(
            ComparisonKind::Solo,
            [ComparisonSubjectRef::player($this->anna), ComparisonSubjectRef::player($this->ben)],
            ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: false),
            withPuzzleNames: true,
        );
        self::assertSame('Night Owls', $named[0]->puzzleName);
    }

    public function testWithDifficultySelectsTiersWithoutChangingTheRows(): void
    {
        $rated = $this->seedPuzzle(500);
        $notYetRated = $this->seedPuzzle(500);
        $neverComputed = $this->seedPuzzle(1000);
        $this->seedDifficulty($rated, 5);
        $this->seedDifficulty($notYetRated, null);

        foreach ([$rated, $notYetRated, $neverComputed] as $puzzle) {
            $this->seedTime($this->anna, $puzzle, 1500, $this->daysAgo(5));
            $this->seedTime($this->ben, $puzzle, 1600, $this->daysAgo(4));
        }

        // Solved by Anna alone: still dropped by "puzzles to show", tier or not
        $alone = $this->seedPuzzle(500);
        $this->seedDifficulty($alone, 2);
        $this->seedTime($this->anna, $alone, 1500, $this->daysAgo(5));

        $refs = [ComparisonSubjectRef::player($this->anna), ComparisonSubjectRef::player($this->ben)];
        $criteria = ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: true);
        $plain = $this->query->forSubjects(ComparisonKind::Solo, $refs, $criteria, withPuzzleNames: true);
        $withDifficulty = $this->query->forSubjects(ComparisonKind::Solo, $refs, $criteria, withPuzzleNames: true, withDifficulty: true);

        self::assertCount(6, $withDifficulty);
        self::assertEqualsCanonicalizing(self::summary($plain), self::summary($withDifficulty), 'The same rows, only with tiers');

        $tiers = [];

        foreach ($withDifficulty as $row) {
            $tiers[$row->puzzleId] = $row->difficultyTier;
        }

        self::assertSame(5, $tiers[$rated]);
        self::assertArrayHasKey($notYetRated, $tiers);
        self::assertNull($tiers[$notYetRated], 'Computed, but no tier yet');
        self::assertArrayHasKey($neverComputed, $tiers);
        self::assertNull($tiers[$neverComputed]);
        self::assertArrayNotHasKey($alone, $tiers);

        foreach ($plain as $row) {
            self::assertNull($row->difficultyTier);
        }

        // The difficulty filter still reads unrated as "no tier", with the tier selected anyway
        $unrated = $this->query->forSubjects(ComparisonKind::Solo, $refs, ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: true, difficulty: ['0']), withDifficulty: true);
        self::assertEqualsCanonicalizing(
            [$notYetRated, $neverComputed],
            array_values(array_unique(array_map(static fn(ComparisonTimeRow $row): string => $row->puzzleId, $unrated))),
        );
    }

    public function testSoloComparesSoloTimesAndPairsCompareExactlyThatSetOfPeople(): void
    {
        $puzzle = $this->seedPuzzle(500);
        $annaAndBen = $this->seedTeam([$this->anna, $this->ben]);
        $annaAndCleo = $this->seedTeam([$this->anna, $this->cleo]);

        $this->seedTime($this->anna, $puzzle, 2000, $this->daysAgo(9));
        $this->seedTime($this->ben, $puzzle, 2100, $this->daysAgo(9));
        // Pair times: whoever tracked them, they belong to the pair
        $this->seedTime($this->anna, $puzzle, 1300, $this->daysAgo(8), teamId: $annaAndBen);
        $pairBest = $this->seedTime($this->ben, $puzzle, 1200, $this->daysAgo(7), teamId: $annaAndBen);
        $this->seedTime($this->cleo, $puzzle, 1400, $this->daysAgo(6), teamId: $annaAndCleo);

        $solo = $this->rows([$this->anna, $this->ben]);
        self::assertSame(2000, $solo[$this->key($this->anna, $puzzle)]->bestSeconds, 'Pair times are not solo times');
        self::assertSame(1, $solo[$this->key($this->anna, $puzzle)]->attempts);

        $pairs = $this->query->forSubjects(
            ComparisonKind::Pairs,
            [
                ComparisonSubjectRef::team($annaAndBen),
                ComparisonSubjectRef::team($annaAndCleo),
                // Refs of the other type are ignored
                ComparisonSubjectRef::player($this->anna),
            ],
            ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: false),
        );

        $byRef = [];

        foreach ($pairs as $row) {
            $byRef[$row->subject->toString()] = $row;
        }

        self::assertCount(2, $byRef);
        self::assertSame(1200, $byRef['t-' . $annaAndBen]->bestSeconds);
        self::assertSame($pairBest, $byRef['t-' . $annaAndBen]->bestTimeId);
        self::assertSame(2, $byRef['t-' . $annaAndBen]->attempts);
        self::assertSame(1400, $byRef['t-' . $annaAndCleo]->bestSeconds);
    }

    public function testTimesOfPlayersTheViewerHidesAreLeftOut(): void
    {
        $viewer = $this->seedPlayer('Viewer', userId: 'auth0|comparison-viewer');
        $puzzle = $this->seedPuzzle(500);
        $this->seedTime($this->anna, $puzzle, 1500, $this->daysAgo(5));
        $this->seedTime($this->ben, $puzzle, 1600, $this->daysAgo(5));
        $this->seedBlock($viewer, $this->ben);

        TestingViewer::signIn(self::getContainer(), $viewer);

        $rows = $this->rows([$this->anna, $this->ben], ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: false, show: 'all'));

        self::assertArrayHasKey($this->key($this->anna, $puzzle), $rows);
        self::assertArrayNotHasKey($this->key($this->ben, $puzzle), $rows);
    }

    public function testNoSubjectsNoQuery(): void
    {
        self::assertSame([], $this->query->forSubjects(ComparisonKind::Teams, [ComparisonSubjectRef::player($this->anna)], ComparisonCriteria::fromUserInput(subjectCount: 1, isMember: false)));
    }

    /**
     * @param list<string> $playerIds
     * @return array<string, ComparisonTimeRow> keyed by "playerId/puzzleId"
     */
    private function rows(array $playerIds, null|ComparisonCriteria $criteria = null): array
    {
        $criteria ??= ComparisonCriteria::fromUserInput(subjectCount: count($playerIds), isMember: false);
        $rows = $this->query->forSubjects(
            ComparisonKind::Solo,
            array_map(ComparisonSubjectRef::player(...), $playerIds),
            $criteria,
        );

        $keyed = [];

        foreach ($rows as $row) {
            $keyed[$this->key($row->subject->id, $row->puzzleId)] = $row;
        }

        return $keyed;
    }

    /**
     * @param list<string> $playerIds
     * @return list<string>
     */
    private function puzzles(array $playerIds, ComparisonCriteria $criteria): array
    {
        return array_values(array_unique(array_map(
            static fn(ComparisonTimeRow $row): string => $row->puzzleId,
            array_values($this->rows($playerIds, $criteria)),
        )));
    }

    /**
     * @param list<ComparisonTimeRow> $rows
     * @return list<string>
     */
    private static function summary(array $rows): array
    {
        return array_map(
            static fn(ComparisonTimeRow $row): string => implode('/', [$row->subject->toString(), $row->puzzleId, $row->bestSeconds, $row->bestTimeId, $row->attempts]),
            $rows,
        );
    }

    private function key(string $subjectId, string $puzzleId): string
    {
        return $subjectId . '/' . $puzzleId;
    }

    private function daysAgo(int $days): DateTimeImmutable
    {
        return $this->now->setTime(12, 0)->modify("-{$days} days");
    }
}
