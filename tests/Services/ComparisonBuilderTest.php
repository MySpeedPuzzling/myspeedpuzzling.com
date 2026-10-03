<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\ComparisonCell;
use SpeedPuzzling\Web\Results\ComparisonLeagueRow;
use SpeedPuzzling\Web\Results\ComparisonPuzzleRow;
use SpeedPuzzling\Web\Results\ComparisonSubject;
use SpeedPuzzling\Web\Results\ComparisonTimeRow;
use SpeedPuzzling\Web\Services\ComparisonBuilder;
use SpeedPuzzling\Web\Value\ComparisonCriteria;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;

final class ComparisonBuilderTest extends TestCase
{
    private const string ME = '018d0000-0000-0000-0000-00000000000a';
    private const string ANNA = '018d0000-0000-0000-0000-00000000000b';
    private const string BEN = '018d0000-0000-0000-0000-00000000000c';
    private const string CLEO = '018d0000-0000-0000-0000-00000000000d';

    private ComparisonBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ComparisonBuilder();
    }

    public function testRanksTiesDeltasAndWinner(): void
    {
        $rows = [
            $this->time(self::ME, 'p1', 1200),
            $this->time(self::ANNA, 'p1', 1000),
            $this->time(self::BEN, 'p1', 1500),
            // A dead heat for the fastest time: nobody wins
            $this->time(self::ME, 'p2', 900),
            $this->time(self::ANNA, 'p2', 900),
            $this->time(self::BEN, 'p2', 1000),
        ];

        $result = $this->builder->build($this->lineUp(self::ME, self::ANNA, self::BEN, self::CLEO), $rows, $this->criteria(4, show: 'all'));
        $p1 = $this->row($result->rows, 'p1');

        self::assertSame(3, $p1->solvedBy());
        self::assertSame(['p-' . self::ME, 'p-' . self::ANNA, 'p-' . self::BEN], array_keys($p1->cells), 'Cells in line-up order');
        self::assertSame([self::ANNA, self::ME, self::BEN], array_map(static fn(ComparisonCell $cell): string => $cell->subject->id, $p1->ranked));
        self::assertSame([2, 1, 3], array_map(static fn(ComparisonCell $cell): int => $cell->rank, array_values($p1->cells)));
        self::assertSame(1000, $p1->fastestSeconds);
        self::assertSame(200, $p1->cell(ComparisonSubjectRef::player(self::ME))?->deltaSeconds);
        self::assertEqualsWithDelta(0.2, $p1->cell(ComparisonSubjectRef::player(self::ME))->deltaRatio, 1e-9);
        self::assertTrue($p1->cell(ComparisonSubjectRef::player(self::ANNA))?->isFastest());
        self::assertSame(self::ANNA, $p1->winner?->id);
        self::assertSame(['p-' . self::CLEO], array_map(static fn(ComparisonSubjectRef $ref): string => $ref->toString(), $p1->notSolvedBy));

        $p2 = $this->row($result->rows, 'p2');
        self::assertNull($p2->winner, 'Equal fastest times: nobody wins');
        self::assertSame([1, 1, 3], array_map(static fn(ComparisonCell $cell): int => $cell->rank, array_values($p2->cells)));
        self::assertSame([self::ME, self::ANNA, self::BEN], array_map(static fn(ComparisonCell $cell): string => $cell->subject->id, $p2->ranked), 'Equal times keep the line-up order');
    }

    public function testShowFilterAndUnavailableSubjects(): void
    {
        $rows = [
            $this->time(self::ME, 'both', 1000),
            $this->time(self::ANNA, 'both', 1100),
            $this->time(self::ME, 'mine', 1000),
            // Cleo is no longer available: her rows are ignored, she is not part of the line-up
            $this->time(self::CLEO, 'mine', 900),
        ];
        $subjects = [
            $this->subject(self::ME, self: true),
            $this->subject(self::ANNA),
            ComparisonSubject::unavailable(ComparisonSubjectRef::player(self::CLEO), ComparisonKind::Solo),
        ];

        $both = $this->builder->build($subjects, $rows, $this->criteria(2));
        self::assertSame(['p-' . self::ME, 'p-' . self::ANNA], array_map(static fn(ComparisonSubjectRef $ref): string => $ref->toString(), $both->subjects));
        self::assertSame(['both'], array_map(static fn(ComparisonPuzzleRow $row): string => $row->puzzleId, $both->rows));
        self::assertTrue($both->hasTwoSubjects());

        $all = $this->builder->build($subjects, $rows, $this->criteria(2, show: 'all'));
        self::assertCount(2, $all->rows);
        self::assertNull($this->row($all->rows, 'mine')->winner, 'A single solver wins nothing');
    }

    public function testFirstTriesCompareTheFirstTry(): void
    {
        $rows = [
            $this->time(self::ME, 'p1', 1000, firstTrySeconds: 1500, firstTryTimeId: 'me-first'),
            $this->time(self::ANNA, 'p1', 1200, firstTrySeconds: 1300, firstTryTimeId: 'anna-first'),
            // No first try on p2 for Anna
            $this->time(self::ME, 'p2', 1000, firstTrySeconds: 1000, firstTryTimeId: 'me-p2'),
            $this->time(self::ANNA, 'p2', 900),
        ];

        $result = $this->builder->build($this->lineUp(self::ME, self::ANNA), $rows, $this->criteria(2, isMember: true, times: 'first'));

        self::assertCount(1, $result->rows);
        $p1 = $result->rows[0];
        self::assertSame(self::ANNA, $p1->winner?->id);
        $mine = $p1->cell(ComparisonSubjectRef::player(self::ME));
        self::assertSame(1500, $mine?->seconds);
        self::assertSame('me-first', $mine->timeId);
        self::assertTrue($mine->isFirstTry());
        self::assertSame(1000, $mine->bestSeconds, 'The best time stays available for the "best of N" line');
    }

    public function testLeagueTable(): void
    {
        $rows = [
            // p1: Anna wins
            $this->time(self::ME, 'p1', 1100),
            $this->time(self::ANNA, 'p1', 1000),
            $this->time(self::BEN, 'p1', 1200),
            // p2: Anna wins
            $this->time(self::ANNA, 'p2', 500),
            $this->time(self::BEN, 'p2', 1000),
            // p3: me
            $this->time(self::ME, 'p3', 800),
            $this->time(self::BEN, 'p3', 1000),
            // p4: only me - counts as solved, not as compared
            $this->time(self::ME, 'p4', 700),
        ];

        $result = $this->builder->build($this->lineUp(self::ME, self::ANNA, self::BEN), $rows, $this->criteria(3, show: 'all'));
        $league = $result->league;

        self::assertSame([self::ANNA, self::ME, self::BEN], array_map(static fn($line): string => $line->subject->id, $league));
        self::assertSame([1, 2, 3], array_map(static fn($line): int => $line->position, $league));

        self::assertSame(2, $league[0]->wins);
        self::assertSame(2, $league[0]->solved);
        self::assertSame(0.0, $league[0]->gap);
        self::assertFalse($league[0]->isSelf);

        self::assertSame(1, $league[1]->wins);
        self::assertSame(3, $league[1]->solved);
        self::assertSame(2, $league[1]->compared);
        // p1 +10 %, p3 0 % → median 5 %
        self::assertEqualsWithDelta(0.05, $league[1]->gap, 1e-9);
        self::assertTrue($league[1]->isSelf);

        self::assertSame(0, $league[2]->wins);
        // p1 +20 %, p2 +100 %, p3 +25 % → median 25 %
        self::assertEqualsWithDelta(0.25, $league[2]->gap, 1e-9);
    }

    public function testLeagueWithoutComparedPuzzlesHasNoGap(): void
    {
        $result = $this->builder->build($this->lineUp(self::ME, self::ANNA), [$this->time(self::ME, 'p1', 900)], $this->criteria(2, show: 'all'));

        self::assertNull($result->league[1]->gap);
        self::assertSame(self::ANNA, $result->league[1]->subject->id, 'Nothing compared ranks last');
    }

    public function testHeadToHead(): void
    {
        $rows = [
            $this->time(self::ME, 'p1', 900),
            $this->time(self::ANNA, 'p1', 1000),
            $this->time(self::ME, 'p2', 800),
            $this->time(self::ANNA, 'p2', 1000),
            $this->time(self::ME, 'p3', 1200),
            $this->time(self::ANNA, 'p3', 1000),
            $this->time(self::ME, 'p4', 1000),
            $this->time(self::ANNA, 'p4', 1000),
            $this->time(self::ME, 'p5', 500),
        ];

        $result = $this->builder->build($this->lineUp(self::ME, self::ANNA), $rows, $this->criteria(2, show: 'all'));
        $duel = $result->headToHead;

        self::assertNotNull($duel);
        self::assertSame(self::ME, $duel->a->id);
        self::assertSame(self::ANNA, $duel->b->id);
        self::assertSame(2, $duel->winsA);
        self::assertSame(1, $duel->winsB);
        self::assertSame(1, $duel->ties);
        self::assertSame(4, $duel->shared);
        // Ratios 0.9, 0.8, 1.2, 1.0 → geometric median of 0.9 and 1.0 = √0.9 ≈ 0.9487 → 5.13 % faster
        self::assertSame(self::ME, $duel->faster?->id);
        self::assertEqualsWithDelta((1 - sqrt(0.9)) * 100, $duel->fasterPercent, 1e-9);

        $even = $this->builder->build($this->lineUp(self::ME, self::ANNA), [$this->time(self::ME, 'p1', 900), $this->time(self::ANNA, 'p1', 900)], $this->criteria(2));
        self::assertNotNull($even->headToHead);
        self::assertNull($even->headToHead->faster);
        self::assertNull($even->headToHead->fasterPercent);
    }

    public function testHeadToHeadMatrix(): void
    {
        $rows = [
            $this->time(self::ME, 'p1', 900),
            $this->time(self::ANNA, 'p1', 1000),
            $this->time(self::BEN, 'p1', 1100),
            $this->time(self::ANNA, 'p2', 700),
            $this->time(self::BEN, 'p2', 600),
        ];

        $result = $this->builder->build($this->lineUp(self::ME, self::ANNA, self::BEN), $rows, $this->criteria(3));

        self::assertSame(1, $result->beats['p-' . self::ME]['p-' . self::ANNA]);
        self::assertSame(0, $result->beats['p-' . self::ANNA]['p-' . self::ME]);
        self::assertSame(1, $result->beats['p-' . self::ANNA]['p-' . self::BEN]);
        self::assertSame(1, $result->beats['p-' . self::BEN]['p-' . self::ANNA]);
        self::assertSame(2, $result->shared['p-' . self::ANNA]['p-' . self::BEN]);
        self::assertSame(1, $result->shared['p-' . self::ME]['p-' . self::BEN]);
        self::assertArrayNotHasKey('p-' . self::ME, $result->beats['p-' . self::ME]);
    }

    public function testHighlightDefaults(): void
    {
        $rows = [$this->time(self::ME, 'p1', 900), $this->time(self::ANNA, 'p1', 1000)];

        // You vs the one added last
        $mine = $this->builder->build($this->lineUpOf(self::ME, [self::ANNA, self::ME, self::BEN, self::CLEO]), $rows, $this->criteria(4));
        self::assertSame([self::ME, self::CLEO], [$mine->highlightA?->id, $mine->highlightB?->id]);

        // Not in the line-up: the first two
        $others = $this->builder->build($this->lineUpOf(null, [self::ANNA, self::BEN, self::CLEO]), $rows, $this->criteria(3));
        self::assertSame([self::ANNA, self::BEN], [$others->highlightA?->id, $others->highlightB?->id]);
        self::assertNull($others->self);

        // A picked alone is paired with you
        $pickedA = $this->builder->build($this->lineUp(self::ME, self::ANNA, self::BEN), $rows, $this->criteria(3, highlightA: 'p-' . self::BEN));
        self::assertSame([self::BEN, self::ME], [$pickedA->highlightA?->id, $pickedA->highlightB?->id]);

        // Both picked
        $picked = $this->builder->build($this->lineUp(self::ME, self::ANNA, self::BEN), $rows, $this->criteria(3, highlightA: 'p-' . self::BEN, highlightB: 'p-' . self::ANNA));
        self::assertSame([self::BEN, self::ANNA], [$picked->highlightA?->id, $picked->highlightB?->id]);

        // Only B picked: you are A
        $pickedB = $this->builder->build($this->lineUp(self::ME, self::ANNA, self::BEN), $rows, $this->criteria(3, highlightB: 'p-' . self::ANNA));
        self::assertSame([self::ME, self::ANNA], [$pickedB->highlightA?->id, $pickedB->highlightB?->id]);

        // Somebody who is not (or no longer) in the line-up falls back to the default
        $gone = $this->builder->build($this->lineUp(self::ME, self::ANNA, self::BEN), $rows, $this->criteria(3, highlightA: 'p-' . self::CLEO));
        self::assertSame([self::ME, self::BEN], [$gone->highlightA?->id, $gone->highlightB?->id]);

        $alone = $this->builder->build($this->lineUp(self::ME), $rows, $this->criteria(1));
        self::assertSame(self::ME, $alone->highlightA?->id);
        self::assertNull($alone->highlightB);
        self::assertNull($alone->headToHead);

        $nobody = $this->builder->build([], $rows, $this->criteria(0));
        self::assertNull($nobody->highlightA);
        self::assertTrue($nobody->isEmpty());
    }

    public function testSorting(): void
    {
        $rows = [
            // me - Anna: p1 -300 (lead), p2 +200 (lag), p3 0, p4 only me
            $this->time(self::ME, 'p1', 700, day: '2026-09-01', pieces: 1000, name: 'banana', tier: 2),
            $this->time(self::ANNA, 'p1', 1000, day: '2026-09-03', pieces: 1000, name: 'banana', tier: 2),
            $this->time(self::ME, 'p2', 1200, day: '2026-09-10', pieces: 300, name: 'Apple', tier: 5),
            $this->time(self::ANNA, 'p2', 1000, day: '2026-08-01', pieces: 300, name: 'Apple', tier: 5),
            $this->time(self::ME, 'p3', 1000, day: '2026-07-01', pieces: 500, name: 'cherry 10', tier: null),
            $this->time(self::ANNA, 'p3', 1000, day: '2026-07-02', pieces: 500, name: 'cherry 10', tier: null),
            $this->time(self::ME, 'p4', 500, day: '2026-09-20', pieces: 500, name: 'cherry 9', tier: 2),
        ];
        $lineUp = $this->lineUp(self::ME, self::ANNA);

        $order = fn(string $sort, bool $isMember = false): array => array_map(
            static fn(ComparisonPuzzleRow $row): string => $row->puzzleId,
            $this->builder->build($lineUp, $rows, $this->criteria(2, isMember: $isMember, show: 'all', sort: $sort))->rows,
        );

        self::assertSame(['p4', 'p2', 'p1', 'p3'], $order('recent'));
        self::assertSame(['p1', 'p3', 'p2', 'p4'], $order('lead'), 'Biggest lead first, puzzles without both last');
        self::assertSame(['p2', 'p3', 'p1', 'p4'], $order('lag'));
        self::assertSame(['p2', 'p1', 'p4', 'p3'], $order('name'), 'Natural, case-insensitive');
        self::assertSame(['p2', 'p4', 'p3', 'p1'], $order('pieces'));
        self::assertSame(['p2', 'p4', 'p1', 'p3'], $order('difficulty', isMember: true), 'Hardest first (then fewer pieces), unrated last');
    }

    public function testPagingWindow(): void
    {
        $rows = [];

        for ($i = 1; $i <= 120; $i++) {
            $day = (new DateTimeImmutable('2026-01-01'))->modify("+{$i} days")->format('Y-m-d');
            $rows[] = $this->time(self::ME, sprintf('p%03d', $i), 1000, day: $day);
            $rows[] = $this->time(self::ANNA, sprintf('p%03d', $i), 1100, day: $day);
        }

        $first = $this->builder->build($this->lineUp(self::ME, self::ANNA), $rows, $this->criteria(2));
        self::assertSame(120, $first->total);
        self::assertCount(50, $first->page);
        self::assertSame('p120', $first->pagePuzzleIds[0], 'Most recent first');
        self::assertTrue($first->hasMore);
        self::assertSame(70, $first->remaining);
        self::assertCount(120, $first->rows, 'Summaries and charts see every shown puzzle');
        self::assertSame(120, $first->league[0]->wins);

        $more = $this->builder->build($this->lineUp(self::ME, self::ANNA), $rows, $this->criteria(2, limit: '100'));
        self::assertCount(100, $more->page);
        self::assertSame(20, $more->remaining);

        $last = $this->builder->build($this->lineUp(self::ME, self::ANNA), $rows, $this->criteria(2, offset: '100'));
        self::assertCount(20, $last->page);
        self::assertSame('p020', $last->pagePuzzleIds[0]);
        self::assertFalse($last->hasMore);
        self::assertSame(0, $last->remaining);
    }

    /**
     * "150 puzzles in common" must visibly be wins A + wins B + ties - an equal time is nobody's win, and the head to head
     * says how many there were (docs/features/player-comparison.md, "Data rules")
     */
    public function testHeadToHeadNumbersAddUpToThePuzzlesBothSolved(): void
    {
        $rows = [];

        foreach (range(1, 150) as $i) {
            $puzzleId = sprintf('p%03d', $i);
            // 88 wins for me, 59 for Anna, 3 equal times
            $mine = match (true) {
                $i <= 88 => 900,
                $i <= 147 => 1100,
                default => 1000,
            };
            $rows[] = $this->time(self::ME, $puzzleId, $mine);
            $rows[] = $this->time(self::ANNA, $puzzleId, 1000);
        }

        // Solved by one of them only: listed with "all puzzles", never part of the head to head
        $rows[] = $this->time(self::ME, 'only-mine', 700);

        $both = $this->builder->build($this->lineUp(self::ME, self::ANNA), $rows, $this->criteria(2));
        $h2h = $both->headToHead;

        self::assertNotNull($h2h);
        self::assertSame(88, $h2h->winsA);
        self::assertSame(59, $h2h->winsB);
        self::assertSame(3, $h2h->ties);
        self::assertSame(150, $h2h->shared);
        self::assertSame(150, $both->total, 'The list is the puzzles both solved');
        self::assertSame(3, $both->ties);
        self::assertSame($both->total, $h2h->winsA + $h2h->winsB + $h2h->ties);
        self::assertSame($both->total, self::wins($both->league) + $both->ties);

        $all = $this->builder->build($this->lineUp(self::ME, self::ANNA), $rows, $this->criteria(2, show: 'all'));
        self::assertSame(151, $all->total);
        self::assertSame(150, $all->headToHead?->shared, 'The head to head counts what both solved, whatever is listed');
        self::assertSame(3, $all->ties, 'A single solver is no tie');
        self::assertSame($all->total, self::wins($all->league) + $all->ties + 1, '+ the one puzzle only I solved');
    }

    /**
     * A line-up: the wins of everyone + the ties for the fastest time (+ the puzzles only one solved, with "all
     * puzzles") are the listed puzzles - a tie further down the ranking still has a winner
     */
    public function testLeagueWinsAndTiesAddUpToTheListedPuzzles(): void
    {
        $rows = [
            // Anna wins
            $this->time(self::ME, 'p1', 1100),
            $this->time(self::ANNA, 'p1', 1000),
            $this->time(self::BEN, 'p1', 1200),
            // Me and Ben share the fastest time: a tie, nobody wins
            $this->time(self::ME, 'p2', 900),
            $this->time(self::BEN, 'p2', 900),
            $this->time(self::ANNA, 'p2', 950),
            // Anna and Ben share the second place: I still win
            $this->time(self::ME, 'p3', 800),
            $this->time(self::ANNA, 'p3', 1000),
            $this->time(self::BEN, 'p3', 1000),
            // Two of us, the same time: a tie
            $this->time(self::ANNA, 'p4', 700),
            $this->time(self::BEN, 'p4', 700),
            // Only Ben
            $this->time(self::BEN, 'p5', 600),
        ];
        $lineUp = $this->lineUp(self::ME, self::ANNA, self::BEN);

        $default = $this->builder->build($lineUp, $rows, $this->criteria(3));
        self::assertSame(4, $default->total, 'Solved by 2+');
        self::assertSame(2, $default->ties);
        self::assertSame(2, self::wins($default->league));
        self::assertSame($default->total, self::wins($default->league) + $default->ties);

        $all = $this->builder->build($lineUp, $rows, $this->criteria(3, show: 'all'));
        self::assertSame(5, $all->total);
        self::assertSame(2, $all->ties);
        self::assertSame($all->total, self::wins($all->league) + $all->ties + 1, '+ the puzzle only Ben solved');

        // First tries count the same way, over the first tries
        $firstTries = [
            $this->time(self::ME, 'p1', 800, firstTrySeconds: 1000, firstTryTimeId: 'me-p1'),
            $this->time(self::ANNA, 'p1', 900, firstTrySeconds: 1000, firstTryTimeId: 'anna-p1'),
            $this->time(self::ME, 'p2', 800, firstTrySeconds: 800, firstTryTimeId: 'me-p2'),
            $this->time(self::ANNA, 'p2', 700, firstTrySeconds: 900, firstTryTimeId: 'anna-p2'),
        ];
        $first = $this->builder->build($this->lineUp(self::ME, self::ANNA), $firstTries, $this->criteria(2, isMember: true, times: 'first'));
        self::assertSame(1, $first->ties, 'Equal first tries, whatever the best times');
        self::assertSame(1, $first->headToHead?->winsA);
        self::assertSame(0, $first->headToHead->winsB);
        self::assertSame(2, $first->headToHead->shared);
    }

    public function testPairsAndTeamsAreSelfWhenTheViewerIsIn(): void
    {
        $ours = ComparisonSubjectRef::team('018d0000-0000-0000-0000-0000000000f1');
        $theirs = ComparisonSubjectRef::team('018d0000-0000-0000-0000-0000000000f2');
        $subjects = [
            new ComparisonSubject(ref: $theirs, kind: ComparisonKind::Pairs, isAvailable: true, teamSize: 2),
            new ComparisonSubject(ref: $ours, kind: ComparisonKind::Pairs, isAvailable: true, teamSize: 2, includesViewer: true),
        ];
        $rows = [
            new ComparisonTimeRow($ours, 'p1', 500, 1, 1000, 'ours', new DateTimeImmutable('2026-09-01'), null, null, null),
            new ComparisonTimeRow($theirs, 'p1', 500, 1, 900, 'theirs', new DateTimeImmutable('2026-09-01'), null, null, null),
        ];

        $result = $this->builder->build($subjects, $rows, $this->criteria(2));

        self::assertTrue($result->self?->equals($ours));
        self::assertTrue($result->highlightA?->equals($ours));
        self::assertTrue($result->highlightB?->equals($theirs));
        self::assertSame(100, $result->rows[0]->lead);
    }

    /**
     * Line-up of available players in this order; ME is "you".
     *
     * @return list<ComparisonSubject>
     */
    private function lineUp(string ...$playerIds): array
    {
        return $this->lineUpOf(self::ME, array_values($playerIds));
    }

    /**
     * @param list<string> $playerIds
     * @return list<ComparisonSubject>
     */
    private function lineUpOf(null|string $self, array $playerIds): array
    {
        return array_map(fn(string $playerId): ComparisonSubject => $this->subject($playerId, self: $playerId === $self), $playerIds);
    }

    private function subject(string $playerId, bool $self = false): ComparisonSubject
    {
        return new ComparisonSubject(
            ref: ComparisonSubjectRef::player($playerId),
            kind: ComparisonKind::Solo,
            isAvailable: true,
            isViewer: $self,
            playerName: 'Player ' . substr($playerId, -1),
        );
    }

    private function time(
        string $playerId,
        string $puzzleId,
        int $seconds,
        string $day = '2026-09-01',
        int $pieces = 500,
        null|int $firstTrySeconds = null,
        null|string $firstTryTimeId = null,
        null|string $name = null,
        null|int $tier = null,
    ): ComparisonTimeRow {
        return new ComparisonTimeRow(
            subject: ComparisonSubjectRef::player($playerId),
            puzzleId: $puzzleId,
            piecesCount: $pieces,
            attempts: 1,
            bestSeconds: $seconds,
            bestTimeId: $playerId . '-' . $puzzleId,
            bestDay: new DateTimeImmutable($day),
            firstTrySeconds: $firstTrySeconds,
            firstTryTimeId: $firstTryTimeId,
            firstTryDay: $firstTrySeconds !== null ? new DateTimeImmutable($day) : null,
            difficultyTier: $tier,
            puzzleName: $name,
        );
    }

    private function criteria(
        int $subjects,
        bool $isMember = false,
        null|string $show = null,
        null|string $times = null,
        null|string $sort = null,
        null|string $highlightA = null,
        null|string $highlightB = null,
        null|string $offset = null,
        null|string $limit = null,
    ): ComparisonCriteria {
        return ComparisonCriteria::fromUserInput(
            subjectCount: $subjects,
            isMember: $isMember,
            show: $show,
            times: $times,
            sort: $sort,
            highlightA: $highlightA,
            highlightB: $highlightB,
            offset: $offset,
            limit: $limit,
        );
    }

    /**
     * @param list<ComparisonLeagueRow> $league
     */
    private static function wins(array $league): int
    {
        return array_sum(array_map(static fn(ComparisonLeagueRow $row): int => $row->wins, $league));
    }

    /**
     * @param list<ComparisonPuzzleRow> $rows
     */
    private function row(array $rows, string $puzzleId): ComparisonPuzzleRow
    {
        foreach ($rows as $row) {
            if ($row->puzzleId === $puzzleId) {
                return $row;
            }
        }

        self::fail("No row for {$puzzleId}");
    }
}
