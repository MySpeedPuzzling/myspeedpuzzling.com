<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\ComparisonChartCard;
use SpeedPuzzling\Web\Results\ComparisonResult;
use SpeedPuzzling\Web\Results\ComparisonSubject;
use SpeedPuzzling\Web\Results\ComparisonTimeRow;
use SpeedPuzzling\Web\Results\PuzzlingTeamMemberView;
use SpeedPuzzling\Web\Services\ComparisonBuilder;
use SpeedPuzzling\Web\Services\ComparisonChartsData;
use SpeedPuzzling\Web\Services\ComparisonChartsFactory;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Value\ComparisonCriteria;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilder;
use Symfony\UX\Chartjs\Model\Chart;

final class ComparisonChartsFactoryTest extends KernelTestCase
{
    private const string ME = '018d0000-0000-0000-0000-00000000000a';
    private const string ANNA = '018d0000-0000-0000-0000-00000000000b';
    private const string BEN = '018d0000-0000-0000-0000-00000000000c';
    private const string CLEO = '018d0000-0000-0000-0000-00000000000d';
    private const string TEAM_MINE = '018d0000-0000-0000-0000-0000000000e1';
    private const string TEAM_OTHER = '018d0000-0000-0000-0000-0000000000e2';

    private const array NAMES = [
        self::ANNA => 'Anna Novak',
        self::BEN => 'Ben Berger',
        // Same initials as Anna: the grid's column headers must still differ
        self::CLEO => 'Adam Nagy',
    ];

    private ComparisonChartsFactory $factory;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->factory = new ComparisonChartsFactory(
            new ChartBuilder(),
            new ComparisonChartsData(),
            self::getContainer()->get(TranslatorInterface::class),
            new PuzzlingTimeFormatter(),
            new MockClock('2026-10-15 10:00:00'),
        );
    }

    public function testADuelHasFourCardsThreeOrMoreAlsoTheHeadToHeadGrid(): void
    {
        [$result, $subjects] = $this->duel();
        self::assertSame(
            [ComparisonChartCard::LEAD_LAG, ComparisonChartCard::SCATTER, ComparisonChartCard::PACE, ComparisonChartCard::FORM],
            array_map(static fn(ComparisonChartCard $card): string => $card->key, $this->factory->cards($result, $subjects)),
        );

        [$result, $subjects] = $this->lineUpOfFour();
        self::assertSame(
            [ComparisonChartCard::LEAD_LAG, ComparisonChartCard::SCATTER, ComparisonChartCard::PACE, ComparisonChartCard::FORM, ComparisonChartCard::MATRIX],
            array_map(static fn(ComparisonChartCard $card): string => $card->key, $this->factory->cards($result, $subjects)),
        );
    }

    public function testNothingWithoutSomebodyToCompareWith(): void
    {
        [$result, $subjects] = $this->comparison([$this->time(self::ME, 'p1', 900)], [self::ME]);

        self::assertSame([], $this->factory->cards($result, $subjects));
    }

    public function testWhoIsAheadPuzzleByPuzzle(): void
    {
        $card = $this->card(ComparisonChartCard::LEAD_LAG, ...$this->duel());
        $chart = $card->chart;

        self::assertInstanceOf(Chart::class, $chart);
        self::assertSame(Chart::TYPE_BAR, $chart->getType());
        self::assertSame('Who\'s ahead, puzzle by puzzle', $card->title);
        self::assertSame('You lead on 3 of 5 puzzles · biggest lead 05:00 on Autumn Lake', $card->takeaway);

        // Lead → lag, top to bottom; coral where I was faster, indigo where Anna was
        $data = $chart->getData();
        self::assertSame(['Autumn Lake', 'Book Lovers', 'Coffee Time', 'Garden Party'], self::at($data, 'labels'));
        self::assertSame([-300, -100, -60, 200], self::at($data, 'datasets', 0, 'data'));
        self::assertSame(
            [ComparisonChartsFactory::COLOR_A, ComparisonChartsFactory::COLOR_A, ComparisonChartsFactory::COLOR_A, ComparisonChartsFactory::COLOR_B],
            self::at($data, 'datasets', 0, 'backgroundColor'),
        );

        $options = $chart->getOptions();
        self::assertSame('y', self::at($options, 'indexAxis'));
        self::assertSame(-300, self::at($options, 'scales', 'x', 'min'));
        self::assertSame(300, self::at($options, 'scales', 'x', 'max'));
        $plugin = self::arrayAt($options, 'plugins', 'comparisonChart');
        self::assertSame([[-300, '5 min'], [0, '0'], [300, '5 min']], self::at($plugin, 'ticks', 'x'));
        self::assertSame(
            ['title' => 'Autumn Lake · 500 pieces', 'lines' => ['You: 00:15:00', 'Anna Novak: 00:20:00', 'You faster by 05:00']],
            self::at($plugin, 'tooltips', 0, 0),
        );
        // The biggest lead and the biggest lag get their value
        self::assertSame([
            ['datasetIndex' => 0, 'index' => 0, 'text' => '05:00'],
            ['datasetIndex' => 0, 'index' => 3, 'text' => '03:20'],
        ], self::at($plugin, 'valueLabels'));
        self::assertFalse(self::at($options, 'plugins', 'legend', 'display'));

        self::assertSame(['You faster', 'Anna Novak faster'], array_column($card->legend, 'label'));
        self::assertSame([ComparisonChartsFactory::COLOR_A, ComparisonChartsFactory::COLOR_B], array_column($card->legend, 'color'));
        self::assertSame('1 dead heat', $card->legendNote, 'Every decided puzzle is a bar');
        self::assertSame('← You faster', $card->axisStart);
        self::assertSame('Anna Novak faster →', $card->axisEnd);
        self::assertStringContainsString('between You and Anna Novak', $card->summary);
    }

    public function testOnlyTheBiggestLeadsAndLagsAreBars(): void
    {
        $rows = [];

        for ($i = 1; $i <= 14; $i++) {
            $rows[] = $this->time(self::ME, "lead{$i}", 1000);
            $rows[] = $this->time(self::ANNA, "lead{$i}", 1000 + $i * 60);
        }

        for ($i = 1; $i <= 12; $i++) {
            $rows[] = $this->time(self::ME, "lag{$i}", 1000 + $i * 60);
            $rows[] = $this->time(self::ANNA, "lag{$i}", 1000);
        }

        $card = $this->card(ComparisonChartCard::LEAD_LAG, ...$this->comparison($rows, [self::ME, self::ANNA]));
        $labels = self::arrayAt($card->chart?->getData(), 'labels');

        self::assertCount(20, $labels);
        self::assertSame('20 of 26 shown: the biggest leads and lags', $card->legendNote);
        // No names asked for: the bars fall back to the piece count
        self::assertSame('500 pc puzzle', $labels[0]);
        self::assertSame('You lead on 14 of 26 puzzles · biggest lead 14:00', $card->takeaway);
    }

    public function testTimeAgainstTime(): void
    {
        $card = $this->card(ComparisonChartCard::SCATTER, ...$this->duel());
        $chart = $card->chart;

        self::assertInstanceOf(Chart::class, $chart);
        self::assertSame(Chart::TYPE_SCATTER, $chart->getType());
        self::assertSame('Your time vs Anna Novak\'s', $card->title);
        self::assertSame('Below the line you were faster: 3 of 5 puzzles', $card->takeaway);

        $datasets = self::arrayAt($chart->getData(), 'datasets');
        self::assertCount(4, $datasets);
        self::assertSame('You faster', self::at($datasets, 0, 'label'));
        self::assertSame(ComparisonChartsFactory::COLOR_A, self::at($datasets, 0, 'pointBackgroundColor'));
        // A's time up, B's across
        self::assertContains(['x' => 1200, 'y' => 900], self::arrayAt($datasets, 0, 'data'));
        self::assertCount(3, self::arrayAt($datasets, 0, 'data'));
        self::assertSame(ComparisonChartsFactory::COLOR_B, self::at($datasets, 1, 'pointBackgroundColor'));
        self::assertSame([['x' => 2300, 'y' => 2500]], self::at($datasets, 1, 'data'));
        self::assertCount(1, self::arrayAt($datasets, 2, 'data'), 'One dead heat');

        // The dashed parity line across the whole square, without a tooltip
        self::assertSame('line', self::at($datasets, 3, 'type'));
        self::assertSame([5, 4], self::at($datasets, 3, 'borderDash'));
        self::assertSame([['x' => 0, 'y' => 0], ['x' => 2700, 'y' => 2700]], self::at($datasets, 3, 'data'));

        $options = $chart->getOptions();
        self::assertSame(2700, self::at($options, 'scales', 'x', 'max'));
        self::assertSame(2700, self::at($options, 'scales', 'y', 'max'));
        self::assertSame([[0, '0'], [900, '0:15'], [1800, '0:30'], [2700, '0:45']], self::at($options, 'plugins', 'comparisonChart', 'ticks', 'y'));
        self::assertSame([], self::at($options, 'plugins', 'comparisonChart', 'tooltips', 3));
        self::assertSame(
            ['title' => 'Garden Party · 1000 pieces', 'lines' => ['You: 00:41:40', 'Anna Novak: 00:38:20']],
            self::at($options, 'plugins', 'comparisonChart', 'tooltips', 1, 0),
        );

        self::assertSame(['You faster', 'Anna Novak faster', 'Same time'], array_column($card->legend, 'label'));
        self::assertSame(['dot', 'dot', 'dash'], array_column($card->legend, 'shape'));
        self::assertSame('↑ Your time', $card->axisStart);
        self::assertSame('Anna Novak\'s time →', $card->axisEnd);
    }

    public function testTheScatterTellsWhoDoesBetterOnTheLongerPuzzles(): void
    {
        $rows = [];

        // Anna wins the three short ones, I win the three long ones
        foreach ([600, 700, 800] as $index => $seconds) {
            $rows[] = $this->time(self::ME, "short{$index}", $seconds + 30);
            $rows[] = $this->time(self::ANNA, "short{$index}", $seconds);
        }

        foreach ([6000, 7000, 8000] as $index => $seconds) {
            $rows[] = $this->time(self::ME, "long{$index}", $seconds);
            $rows[] = $this->time(self::ANNA, "long{$index}", $seconds + 300);
        }

        $card = $this->card(ComparisonChartCard::SCATTER, ...$this->comparison($rows, [self::ME, self::ANNA]));
        self::assertSame('You win more often on the longer puzzles, Anna Novak on the shorter ones', $card->takeaway);

        // Anna highlighted first: the sentence is about her
        $card = $this->card(ComparisonChartCard::SCATTER, ...$this->comparison($rows, [self::ME, self::ANNA], highlightA: 'p-' . self::ANNA, highlightB: 'p-' . self::ME));
        self::assertSame('Anna Novak\'s time vs yours', $card->title);
        self::assertSame('You win more often on the longer puzzles, Anna Novak on the shorter ones', $card->takeaway);
        self::assertSame('↑ Anna Novak\'s time', $card->axisStart);
        self::assertSame('Your time →', $card->axisEnd);
    }

    public function testPaceByPieceCount(): void
    {
        $card = $this->card(ComparisonChartCard::PACE, ...$this->duel());
        $chart = $card->chart;

        self::assertInstanceOf(Chart::class, $chart);
        self::assertSame(Chart::TYPE_SCATTER, $chart->getType());
        self::assertSame('Quickest against the line-up: you at 500 pieces, Anna Novak at 1000 pieces', $card->takeaway);

        $datasets = self::arrayAt($chart->getData(), 'datasets');
        self::assertSame(['You', 'Anna Novak', 'Rest of the line-up'], [self::at($datasets, 0, 'label'), self::at($datasets, 1, 'label'), self::at($datasets, 2, 'label')]);
        self::assertSame(ComparisonChartsFactory::COLOR_A, self::at($datasets, 0, 'pointBackgroundColor'));
        self::assertSame(ComparisonChartsFactory::COLOR_B, self::at($datasets, 1, 'pointBackgroundColor'));
        self::assertSame(ComparisonChartsFactory::COLOR_OTHER, self::at($datasets, 2, 'pointBackgroundColor'));
        // 500 pc: my paces -14.3, -4.8 and 0 % → median -4.8 %
        self::assertSame(['x' => -4.8, 'y' => '500 pc'], self::at($datasets, 0, 'data', 0));
        self::assertSame([], self::at($datasets, 2, 'data'));

        $options = $chart->getOptions();
        self::assertSame(['500 pc', '1000 pc'], self::at($options, 'scales', 'y', 'labels'));
        self::assertSame([[-5, '−5%'], [0, '0'], [5, '+5%']], self::at($options, 'plugins', 'comparisonChart', 'ticks', 'x'));
        self::assertSame(
            ['title' => '500 pieces', 'lines' => ['You: 5% faster than the line-up median', '3 puzzles compared']],
            self::at($options, 'plugins', 'comparisonChart', 'tooltips', 0, 0),
        );

        // Nobody else in the line-up: no gray key
        self::assertSame(['You', 'Anna Novak'], array_column($card->legend, 'label'));
        self::assertSame('← faster', $card->axisStart);
    }

    public function testFormOverTime(): void
    {
        $card = $this->card(ComparisonChartCard::FORM, ...$this->duel());
        $chart = $card->chart;

        self::assertInstanceOf(Chart::class, $chart);
        self::assertSame(Chart::TYPE_LINE, $chart->getType());

        $data = $chart->getData();
        self::assertCount(12, self::arrayAt($data, 'labels'));
        self::assertSame('Nov', self::at($data, 'labels', 0));
        self::assertSame('Oct', self::at($data, 'labels', 11));

        // Up = faster: in August I was 9.5 % faster than the line-up's median, in October on it
        self::assertSame(9.5, self::at($data, 'datasets', 0, 'data', 9));
        self::assertEqualsWithDelta(0.0, self::at($data, 'datasets', 0, 'data', 11), 1e-9);
        self::assertNull(self::at($data, 'datasets', 0, 'data', 10));
        self::assertSame(ComparisonChartsFactory::COLOR_A, self::at($data, 'datasets', 0, 'borderColor'));
        self::assertSame(2, self::at($data, 'datasets', 0, 'borderWidth'));
        self::assertSame(ComparisonChartsFactory::COLOR_B, self::at($data, 'datasets', 1, 'borderColor'));

        $plugin = self::arrayAt($chart->getOptions(), 'plugins', 'comparisonChart');
        self::assertSame([['datasetIndex' => 0, 'text' => 'You'], ['datasetIndex' => 1, 'text' => 'Anna Novak']], self::at($plugin, 'endLabels'));
        self::assertSame(['title' => 'August 2026', 'lines' => ['You: 10% faster than the line-up median']], self::at($plugin, 'tooltips', 0, 9));
        self::assertNull(self::at($plugin, 'tooltips', 0, 10));

        self::assertSame('You lost 10 points on the line-up since August 2026', $card->takeaway);
        self::assertSame('Nov 2025 – Oct 2026', $card->axisEnd);
        self::assertSame(['line', 'line'], array_column($card->legend, 'shape'));
    }

    public function testOthersAreGrayAndThinInFormAndPace(): void
    {
        [$result, $subjects] = $this->lineUpOfFour();

        $form = $this->card(ComparisonChartCard::FORM, $result, $subjects);
        $datasets = self::arrayAt($form->chart?->getData(), 'datasets');
        self::assertSame(
            ['You', 'Ben Berger', 'Anna Novak', 'Adam Nagy'],
            array_map(static fn(int|string $index): mixed => self::at($datasets, $index, 'label'), array_keys($datasets)),
            'A and B first, then the line-up',
        );
        self::assertSame(ComparisonChartsFactory::COLOR_OTHER, self::at($datasets, 2, 'borderColor'));
        self::assertSame(1.25, self::at($datasets, 2, 'borderWidth'));
        self::assertSame(['You', 'Ben Berger', 'Rest of the line-up'], array_column($form->legend, 'label'));

        $pace = $this->card(ComparisonChartCard::PACE, $result, $subjects);
        self::assertNotEmpty(self::arrayAt($pace->chart?->getData(), 'datasets', 2, 'data'));
        self::assertSame(['You', 'Ben Berger', 'Rest of the line-up'], array_column($pace->legend, 'label'));
    }

    public function testHeadToHeadGrid(): void
    {
        $card = $this->card(ComparisonChartCard::MATRIX, ...$this->lineUpOfFour());
        $grid = $card->grid;

        self::assertNotNull($grid);
        self::assertNull($card->chart);
        self::assertSame('Read across: you beat Anna Novak on 2 puzzles', $card->takeaway);
        self::assertSame(['You', 'Ann', 'Ada', 'BB'], array_column($grid['columns'], 'abbr'));
        self::assertSame(ComparisonChartsFactory::RAMP, $grid['ramp']);
        self::assertSame(['a', 'other', 'other', 'b'], array_column($grid['rows'], 'role'));

        $me = $grid['rows'][0]['cells'];
        self::assertTrue($me[0]['diagonal']);
        // Beat Anna on both shared puzzles: the darkest step; Ben on one of two: the middle one
        self::assertSame(['diagonal' => false, 'wins' => 2, 'step' => 5, 'title' => 'You beat Anna Novak on 2 of 2 puzzles both solved'], $me[1]);
        self::assertSame(3, $me[3]['step']);
        // Adam shares nothing with anybody
        self::assertNull($me[2]['step']);
        self::assertNull($me[2]['wins']);
        self::assertSame('You and Adam Nagy have no puzzle in common', $me[2]['title']);
        // Anna never beat me: the lightest step, not blank
        self::assertSame(['diagonal' => false, 'wins' => 0, 'step' => 1, 'title' => 'Anna Novak beat you on 0 of 2 puzzles both solved'], $grid['rows'][1]['cells'][0]);
        self::assertSame('Fewer wins', $grid['rampLow']);
    }

    public function testTooLittleDataBecomesANote(): void
    {
        [$result, $subjects] = $this->comparison([
            $this->time(self::ME, 'p1', 900),
            $this->time(self::ANNA, 'p1', 1000),
            $this->time(self::ME, 'p2', 900),
            $this->time(self::ANNA, 'p2', 800),
        ], [self::ME, self::ANNA]);

        foreach ($this->factory->cards($result, $subjects) as $card) {
            self::assertFalse($card->isShown(), $card->key);
            self::assertNull($card->chart);
            self::assertNull($card->takeaway);
            self::assertSame('Not enough to draw yet – this chart needs at least 3 puzzles compared under these filters.', $card->note);
        }
    }

    public function testPairsAreNamedByTheirNameOrTheirMembers(): void
    {
        $mine = ComparisonSubjectRef::team(self::TEAM_MINE);
        $other = ComparisonSubjectRef::team(self::TEAM_OTHER);
        $subjects = [
            new ComparisonSubject(ref: $mine, kind: ComparisonKind::Pairs, isAvailable: true, teamName: 'Puzzle Pals', teamSize: 2, includesViewer: true),
            new ComparisonSubject(ref: $other, kind: ComparisonKind::Pairs, isAvailable: true, teamSize: 2, members: [
                new PuzzlingTeamMemberView(self::ANNA, 'Anna Novak', 'anna', null, null, false),
                new PuzzlingTeamMemberView(null, null, null, null, 'Jana', false),
            ]),
        ];
        $rows = [];

        foreach (['p1' => [900, 1000], 'p2' => [1000, 1100], 'p3' => [1300, 1200]] as $puzzle => [$mineSeconds, $otherSeconds]) {
            $rows[] = $this->time(self::TEAM_MINE, $puzzle, $mineSeconds, team: true);
            $rows[] = $this->time(self::TEAM_OTHER, $puzzle, $otherSeconds, team: true);
        }

        $result = (new ComparisonBuilder())->build($subjects, $rows, ComparisonCriteria::fromUserInput(subjectCount: 2, isMember: true, show: 'all'));
        $card = $this->card(ComparisonChartCard::LEAD_LAG, $result, $subjects);

        self::assertSame('Puzzle Pals leads on 2 of 3 puzzles · biggest lead 01:40', $card->takeaway);
        self::assertSame(['Puzzle Pals faster', 'Anna Novak & Jana faster'], array_column($card->legend, 'label'));
    }

    /**
     * Me vs Anna, five puzzles: I lead on three (5:00, 1:40, 1:00), Anna on one (3:20), one dead heat
     *
     * @return array{ComparisonResult, list<ComparisonSubject>}
     */
    private function duel(): array
    {
        return $this->comparison([
            $this->time(self::ME, 'p1', 900, name: 'Autumn Lake', day: '2026-08-10'),
            $this->time(self::ANNA, 'p1', 1200, name: 'Autumn Lake', day: '2026-08-10'),
            $this->time(self::ME, 'p2', 1000, name: 'Book Lovers', day: '2026-08-10'),
            $this->time(self::ANNA, 'p2', 1100, name: 'Book Lovers', day: '2026-08-10'),
            $this->time(self::ME, 'p3', 2000, pieces: 1000, name: 'Coffee Time', day: '2026-10-02'),
            $this->time(self::ANNA, 'p3', 2060, pieces: 1000, name: 'Coffee Time', day: '2026-10-02'),
            $this->time(self::ME, 'p4', 2500, pieces: 1000, name: 'Garden Party', day: '2026-10-02'),
            $this->time(self::ANNA, 'p4', 2300, pieces: 1000, name: 'Garden Party', day: '2026-10-02'),
            $this->time(self::ME, 'p5', 1000, name: 'Harbour Lights', day: '2026-10-02'),
            $this->time(self::ANNA, 'p5', 1000, name: 'Harbour Lights', day: '2026-10-02'),
        ], [self::ME, self::ANNA]);
    }

    /**
     * Me, Anna, Adam, Ben (B = Ben, added last). Ben shares only with me and Anna; Adam solved one puzzle alone.
     *
     * @return array{ComparisonResult, list<ComparisonSubject>}
     */
    private function lineUpOfFour(): array
    {
        return $this->comparison([
            $this->time(self::ME, 'p1', 900, day: '2026-08-10'),
            $this->time(self::ANNA, 'p1', 1000, day: '2026-08-10'),
            $this->time(self::BEN, 'p1', 1100, day: '2026-08-10'),
            $this->time(self::ANNA, 'p2', 700, day: '2026-09-10'),
            $this->time(self::BEN, 'p2', 600, day: '2026-09-10'),
            $this->time(self::ME, 'p3', 500, day: '2026-09-10'),
            $this->time(self::BEN, 'p3', 400, day: '2026-09-10'),
            $this->time(self::ME, 'p4', 800, day: '2026-10-01'),
            $this->time(self::ANNA, 'p4', 900, day: '2026-10-01'),
            $this->time(self::CLEO, 'p5', 900, day: '2026-10-01'),
        ], [self::ME, self::ANNA, self::CLEO, self::BEN]);
    }

    /**
     * A value deep inside Chart.js data/options (plain arrays): every step must exist.
     */
    private static function at(mixed $value, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private static function arrayAt(mixed $value, int|string ...$path): array
    {
        $value = self::at($value, ...$path);
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @param list<ComparisonSubject> $subjects
     */
    private function card(string $key, ComparisonResult $result, array $subjects): ComparisonChartCard
    {
        foreach ($this->factory->cards($result, $subjects) as $card) {
            if ($card->key === $key) {
                return $card;
            }
        }

        self::fail("No {$key} card");
    }

    /**
     * @param list<ComparisonTimeRow> $rows
     * @param list<string> $playerIds line-up, ME is "you"
     * @return array{ComparisonResult, list<ComparisonSubject>}
     */
    private function comparison(array $rows, array $playerIds, null|string $highlightA = null, null|string $highlightB = null): array
    {
        $subjects = array_map(static fn(string $playerId): ComparisonSubject => new ComparisonSubject(
            ref: ComparisonSubjectRef::player($playerId),
            kind: ComparisonKind::Solo,
            isAvailable: true,
            isViewer: $playerId === self::ME,
            playerName: self::NAMES[$playerId] ?? 'Me Myself',
            playerCode: 'code' . substr($playerId, -1),
        ), $playerIds);

        $result = (new ComparisonBuilder())->build(
            $subjects,
            $rows,
            ComparisonCriteria::fromUserInput(subjectCount: count($subjects), isMember: true, show: 'all', highlightA: $highlightA, highlightB: $highlightB),
        );

        return [$result, $subjects];
    }

    private function time(string $subjectId, string $puzzleId, int $seconds, string $day = '2026-09-01', int $pieces = 500, null|string $name = null, bool $team = false): ComparisonTimeRow
    {
        return new ComparisonTimeRow(
            subject: $team ? ComparisonSubjectRef::team($subjectId) : ComparisonSubjectRef::player($subjectId),
            puzzleId: $puzzleId,
            piecesCount: $pieces,
            attempts: 1,
            bestSeconds: $seconds,
            bestTimeId: $subjectId . '-' . $puzzleId,
            bestDay: new DateTimeImmutable($day),
            firstTrySeconds: null,
            firstTryTimeId: null,
            firstTryDay: null,
            puzzleName: $name,
        );
    }
}
