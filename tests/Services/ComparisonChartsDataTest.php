<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\ComparisonResult;
use SpeedPuzzling\Web\Results\ComparisonSubject;
use SpeedPuzzling\Web\Results\ComparisonTimeRow;
use SpeedPuzzling\Web\Services\ComparisonBuilder;
use SpeedPuzzling\Web\Services\ComparisonChartsData;
use SpeedPuzzling\Web\Value\ComparisonCriteria;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use SpeedPuzzling\Web\Value\DifficultyTier;

final class ComparisonChartsDataTest extends TestCase
{
    private const string ME = '018d0000-0000-0000-0000-00000000000a';
    private const string ANNA = '018d0000-0000-0000-0000-00000000000b';
    private const string BEN = '018d0000-0000-0000-0000-00000000000c';

    private ComparisonChartsData $charts;

    protected function setUp(): void
    {
        $this->charts = new ComparisonChartsData();
    }

    public function testRolesFollowTheHighlightPair(): void
    {
        $result = $this->comparison([$this->time(self::ME, 'p1', 900), $this->time(self::ANNA, 'p1', 1000), $this->time(self::BEN, 'p1', 1100)]);

        self::assertSame(['p-' . self::ME => 'a', 'p-' . self::ANNA => 'other', 'p-' . self::BEN => 'b'], $this->charts->roles($result));
    }

    public function testLeadLagBarsRunFromTheBiggestLeadToTheBiggestLag(): void
    {
        $rows = [];

        // 12 puzzles where I lead by 10..120 s, 11 where I lag by 10..110 s, 2 dead heats
        for ($i = 1; $i <= 12; $i++) {
            $rows[] = $this->time(self::ME, "lead{$i}", 1000 - $i * 10);
            $rows[] = $this->time(self::ANNA, "lead{$i}", 1000);
        }

        for ($i = 1; $i <= 11; $i++) {
            $rows[] = $this->time(self::ME, "lag{$i}", 1000 + $i * 10);
            $rows[] = $this->time(self::ANNA, "lag{$i}", 1000);
        }

        $rows[] = $this->time(self::ME, 'tie1', 1000);
        $rows[] = $this->time(self::ANNA, 'tie1', 1000);
        $rows[] = $this->time(self::ME, 'tie2', 1000);
        $rows[] = $this->time(self::ANNA, 'tie2', 1000);

        $leadLag = $this->charts->leadLag($this->comparison($rows, [self::ME, self::ANNA]));

        self::assertSame('p-' . self::ME, $leadLag['a']);
        self::assertSame('p-' . self::ANNA, $leadLag['b']);
        self::assertSame(12, $leadLag['aheadCount']);
        self::assertSame(11, $leadLag['behindCount']);
        self::assertSame(2, $leadLag['tiedCount']);
        self::assertCount(20, $leadLag['bars']);
        self::assertSame(-120, $leadLag['bars'][0]['deltaSeconds']);
        self::assertSame('lead12', $leadLag['bars'][0]['puzzleId']);
        self::assertSame(-30, $leadLag['bars'][9]['deltaSeconds']);
        self::assertSame(20, $leadLag['bars'][10]['deltaSeconds'], 'The ten biggest lags: 20..110');
        self::assertSame(110, $leadLag['bars'][19]['deltaSeconds']);
        self::assertSame(880, $leadLag['bars'][0]['aSeconds']);
        self::assertSame(1000, $leadLag['bars'][0]['bSeconds']);
    }

    public function testScatter(): void
    {
        $result = $this->comparison([
            $this->time(self::ME, 'p1', 900),
            $this->time(self::ANNA, 'p1', 1000),
            $this->time(self::ME, 'p2', 2000),
            $this->time(self::ANNA, 'p2', 1500),
            $this->time(self::ME, 'p3', 800),
            $this->time(self::ANNA, 'p3', 800),
            // Only one of the pair: no point
            $this->time(self::ME, 'p4', 3000),
            $this->time(self::BEN, 'p4', 3100),
        ], [self::ME, self::BEN, self::ANNA]);

        $scatter = $this->charts->scatter($result);
        $winners = [];

        foreach ($scatter['points'] as $point) {
            $winners[$point['puzzleId']] = $point['winner'];
        }

        self::assertSame('p-' . self::ANNA, $scatter['b'], 'You vs the one added last');
        self::assertSame(['p1' => 'a', 'p2' => 'b', 'p3' => 'tie'], $winners);
        self::assertSame(2000, $scatter['maxSeconds']);
    }

    public function testPaceByPieceCount(): void
    {
        $result = $this->comparison([
            // 500 pc: line-up median 1000 → me -10 %, Anna 0 %, Ben +10 %
            $this->time(self::ME, 'p500', 900),
            $this->time(self::ANNA, 'p500', 1000),
            $this->time(self::BEN, 'p500', 1100),
            // 1000 pc, two solvers: median 2500 → me -20 %, Anna +20 %
            $this->time(self::ME, 'p1000', 2000, pieces: 1000),
            $this->time(self::ANNA, 'p1000', 3000, pieces: 1000),
            // 550 pc is no preset - left out (like with like)
            $this->time(self::ME, 'p550', 1000, pieces: 550),
            $this->time(self::ANNA, 'p550', 2000, pieces: 550),
            // 300 pc solved by me alone - nothing to compare
            $this->time(self::ME, 'p300', 600, pieces: 300),
        ]);

        $pace = $this->charts->paceByPieces($result);

        self::assertSame(['500', '1000'], array_column($pace, 'key'));
        self::assertSame('500', $pace[0]['label']);
        self::assertEqualsWithDelta(-10.0, $pace[0]['subjects']['p-' . self::ME]['percent'], 1e-9);
        self::assertEqualsWithDelta(0.0, $pace[0]['subjects']['p-' . self::ANNA]['percent'], 1e-9);
        self::assertEqualsWithDelta(10.0, $pace[0]['subjects']['p-' . self::BEN]['percent'], 1e-9);
        self::assertSame(1, $pace[0]['subjects']['p-' . self::ME]['puzzles']);
        self::assertEqualsWithDelta(-20.0, $pace[1]['subjects']['p-' . self::ME]['percent'], 1e-9);
        self::assertEqualsWithDelta(20.0, $pace[1]['subjects']['p-' . self::ANNA]['percent'], 1e-9);
        self::assertArrayNotHasKey('p-' . self::BEN, $pace[1]['subjects']);
    }

    public function testByDifficulty(): void
    {
        $result = $this->comparison([
            // Easy, everybody: line-up median 1000 → me -10 %, Anna 0 %, Ben +10 %; I beat Ben
            $this->time(self::ME, 'easy1', 900, tier: 2),
            $this->time(self::ANNA, 'easy1', 1000, tier: 2),
            $this->time(self::BEN, 'easy1', 1100, tier: 2),
            // Easy, me and Ben: median 950 → me +5.26 %, Ben -5.26 %; Ben beats me
            $this->time(self::ME, 'easy2', 1000, tier: 2),
            $this->time(self::BEN, 'easy2', 900, tier: 2),
            // Hard, me and Anna on the same time - nothing between me and Ben here
            $this->time(self::ME, 'hard', 2000, tier: 5),
            $this->time(self::ANNA, 'hard', 2000, tier: 5),
            // Not rated yet: left out
            $this->time(self::ME, 'unrated', 500),
            $this->time(self::BEN, 'unrated', 1000),
            // Very hard, but mine alone: nothing compared, no row
            $this->time(self::ME, 'solo', 3000, tier: 6),
        ]);

        $tiers = $this->charts->byDifficulty($result);

        self::assertSame([DifficultyTier::Easy, DifficultyTier::Hard], array_column($tiers, 'tier'));
        self::assertSame([2, 1], array_column($tiers, 'puzzles'));

        $easy = $tiers[0]['subjects'];
        self::assertEqualsWithDelta(-2.368, $easy['p-' . self::ME]['percent'], 1e-3, 'Median of -10 % and +5.26 %');
        self::assertSame(2, $easy['p-' . self::ME]['puzzles']);
        self::assertEqualsWithDelta(0.0, $easy['p-' . self::ANNA]['percent'], 1e-9);
        self::assertSame(1, $easy['p-' . self::ANNA]['puzzles']);
        self::assertEqualsWithDelta(2.368, $easy['p-' . self::BEN]['percent'], 1e-3);
        // Me (A) vs Ben (B, added last)
        self::assertSame(['a' => 1, 'b' => 1, 'ties' => 0], $tiers[0]['wins']);

        self::assertSame(['p-' . self::ME, 'p-' . self::ANNA], array_keys($tiers[1]['subjects']));
        self::assertSame(['a' => 0, 'b' => 0, 'ties' => 0], $tiers[1]['wins'], 'Ben did not solve it');
    }

    public function testByDifficultyCountsDeadHeatsAndNeedsTiers(): void
    {
        $result = $this->comparison([
            $this->time(self::ME, 'p1', 1000, tier: 6),
            $this->time(self::ANNA, 'p1', 1000, tier: 6),
            $this->time(self::ME, 'p2', 900, tier: 6),
            $this->time(self::ANNA, 'p2', 1000, tier: 6),
        ], [self::ME, self::ANNA]);

        $tiers = $this->charts->byDifficulty($result);

        self::assertCount(1, $tiers);
        self::assertSame(DifficultyTier::VeryHard, $tiers[0]['tier']);
        self::assertSame(['a' => 1, 'b' => 0, 'ties' => 1], $tiers[0]['wins']);

        // The aggregate did not select tiers: nothing to show
        $withoutTiers = $this->comparison([$this->time(self::ME, 'p1', 900), $this->time(self::ANNA, 'p1', 1000)], [self::ME, self::ANNA]);
        self::assertSame([], $this->charts->byDifficulty($withoutTiers));
    }

    public function testFormOverTheLastTwelveMonths(): void
    {
        $now = new DateTimeImmutable('2026-10-15 10:00:00');
        $result = $this->comparison([
            $this->time(self::ME, 'p1', 900, day: '2026-10-02'),
            $this->time(self::ANNA, 'p1', 1100, day: '2026-09-28'),
            $this->time(self::ME, 'p2', 1000, day: '2026-10-05'),
            $this->time(self::ANNA, 'p2', 1000, day: '2026-10-06'),
            // Older than twelve months: left out
            $this->time(self::ME, 'p3', 500, day: '2025-09-30'),
            $this->time(self::ANNA, 'p3', 1500, day: '2025-09-30'),
        ], [self::ME, self::ANNA]);

        $form = $this->charts->form($result, $now);

        self::assertCount(12, $form['months']);
        self::assertSame('2025-11', $form['months'][0]);
        self::assertSame('2026-10', $form['months'][11]);
        // October: p1 -10 %, p2 0 % → median -5 %
        self::assertEqualsWithDelta(-5.0, $form['series']['p-' . self::ME][11], 1e-9);
        self::assertNull($form['series']['p-' . self::ME][10]);
        // Anna: p1 +10 % in September, p2 0 % in October
        self::assertEqualsWithDelta(10.0, $form['series']['p-' . self::ANNA][10], 1e-9);
        self::assertEqualsWithDelta(0.0, $form['series']['p-' . self::ANNA][11], 1e-9);
        self::assertSame([null, null, null, null, null, null, null, null, null, null], array_slice($form['series']['p-' . self::ME], 0, 10));
    }

    public function testHeadToHeadGrid(): void
    {
        $result = $this->comparison([
            $this->time(self::ME, 'p1', 900),
            $this->time(self::ANNA, 'p1', 1000),
            $this->time(self::BEN, 'p1', 1100),
            $this->time(self::ANNA, 'p2', 700),
            $this->time(self::BEN, 'p2', 600),
        ]);

        $matrix = $this->charts->matrix($result);

        self::assertSame(['p-' . self::ME, 'p-' . self::ANNA, 'p-' . self::BEN], $matrix['subjects']);
        self::assertSame(['wins' => 1, 'shared' => 2, 'share' => 0.5], $matrix['cells']['p-' . self::ANNA]['p-' . self::BEN]);
        self::assertSame(['wins' => 1, 'shared' => 1, 'share' => 1.0], $matrix['cells']['p-' . self::ME]['p-' . self::ANNA]);
        self::assertSame(2, $matrix['maxShared']);
    }

    public function testEverythingAtOnce(): void
    {
        $all = $this->charts->all($this->comparison([$this->time(self::ME, 'p1', 900), $this->time(self::ANNA, 'p1', 1000)], [self::ME, self::ANNA]), new DateTimeImmutable());

        self::assertSame('a', $all['roles']['p-' . self::ME]);
        self::assertCount(1, $all['scatter']['points']);
        self::assertSame(1, $all['leadLag']['aheadCount']);
        self::assertSame('500', $all['pace'][0]['key']);
        self::assertSame([], $all['difficulty']);
        self::assertCount(ComparisonChartsData::FORM_MONTHS, $all['form']['months']);
        self::assertSame(1, $all['matrix']['cells']['p-' . self::ME]['p-' . self::ANNA]['wins']);
    }

    /**
     * @param list<ComparisonTimeRow> $rows
     * @param list<string> $playerIds line-up, ME is "you"
     */
    private function comparison(array $rows, array $playerIds = [self::ME, self::ANNA, self::BEN]): ComparisonResult
    {
        $subjects = array_map(static fn(string $playerId): ComparisonSubject => new ComparisonSubject(
            ref: ComparisonSubjectRef::player($playerId),
            kind: ComparisonKind::Solo,
            isAvailable: true,
            isViewer: $playerId === self::ME,
        ), $playerIds);

        return (new ComparisonBuilder())->build(
            $subjects,
            $rows,
            ComparisonCriteria::fromUserInput(subjectCount: count($subjects), isMember: true, show: 'all'),
        );
    }

    private function time(string $playerId, string $puzzleId, int $seconds, string $day = '2026-09-01', int $pieces = 500, null|int $tier = null): ComparisonTimeRow
    {
        return new ComparisonTimeRow(
            subject: ComparisonSubjectRef::player($playerId),
            puzzleId: $puzzleId,
            piecesCount: $pieces,
            attempts: 1,
            bestSeconds: $seconds,
            bestTimeId: $playerId . '-' . $puzzleId,
            bestDay: new DateTimeImmutable($day),
            firstTrySeconds: null,
            firstTryTimeId: null,
            firstTryDay: null,
            difficultyTier: $tier,
        );
    }
}
