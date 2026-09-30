<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component\Chart;

use DateTimeImmutable;
use SpeedPuzzling\Web\Component\Chart\PuzzleTimesChart;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\PuzzleSolversGroup;
use SpeedPuzzling\Web\Services\LeaderboardHistogramBuilder;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Value\Puzzler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;

/**
 * The members' chart above the puzzle leaderboard (docs/features/puzzle-leaderboard-chart.md):
 * a bar per row up to INDIVIDUAL_BARS_MAX rows, the distribution of the times above that.
 */
final class PuzzleTimesChartTest extends KernelTestCase
{
    private const string VIEWER_COLOR = 'rgba(254, 64, 66, 1)';

    public function testUpToTheLimitEveryRowIsItsOwnBar(): void
    {
        $chart = $this->chart(self::soloRows(PuzzleTimesChart::INDIVIDUAL_BARS_MAX));

        self::assertFalse($chart->isDistribution());
        $data = $chart->getChart()->getData();
        self::assertIsArray($data['labels']);
        self::assertCount(PuzzleTimesChart::INDIVIDUAL_BARS_MAX, $data['labels']);
        self::assertSame('1. Solver 1', $data['labels'][0]);
    }

    public function testBarPerRowChartMarksTheMedianAcrossTheBarsAndTheViewersBar(): void
    {
        // 20 solvers 51 ... 70 minutes: the median is between the 10th (01:00:00) and the 11th (01:01:00)
        $chart = $this->chart(self::soloRows(20), viewerId: 'player-7');

        $data = $chart->getChart()->getData();
        $options = $chart->getChart()->getOptions();

        self::assertSame([
            ['kind' => 'horizontal', 'value' => 3630, 'label' => 'Median', 'color' => '#4b566b', 'dashed' => true],
            ['kind' => 'bar', 'index' => 6, 'label' => 'You', 'color' => self::VIEWER_COLOR],
        ], self::markers($options));

        // The viewer's bar stays solid red, the others keep their colours
        self::assertIsArray($data['datasets']);
        self::assertIsArray($data['datasets'][0]);
        self::assertIsArray($data['datasets'][0]['backgroundColor']);
        self::assertSame(self::VIEWER_COLOR, $data['datasets'][0]['backgroundColor'][6]);
        self::assertSame([6], array_keys($data['datasets'][0]['backgroundColor'], self::VIEWER_COLOR, true));

        // Room above the tallest bar for the label, the same summary for screen readers
        self::assertSame(['padding' => ['top' => 18]], $options['layout']);
        self::assertSame('Times of 20 puzzlers, median 01:00:30. Your time: 00:57:00.', $chart->getSummary());
    }

    public function testBarPerRowChartWithoutTheViewerMarksOnlyTheMedian(): void
    {
        $chart = $this->chart(self::soloRows(3));

        self::assertSame(['Median'], array_column(self::markers($chart->getChart()->getOptions()), 'label'));
        self::assertSame('Times of 3 puzzlers, median 00:52:00.', $chart->getSummary());
    }

    public function testLongerLeaderboardsShowTheDistribution(): void
    {
        $chart = $this->chart(self::soloRows(PuzzleTimesChart::INDIVIDUAL_BARS_MAX + 1));

        self::assertTrue($chart->isDistribution());

        $data = $chart->getChart()->getData();
        $options = $chart->getChart()->getOptions();

        // Two stacked datasets, first tries and repeats - together every row
        self::assertSame(PuzzleTimesChart::INDIVIDUAL_BARS_MAX + 1, array_sum(self::datasetValues($data, 0)) + array_sum(self::datasetValues($data, 1)));
        self::assertIsArray($data['labels']);
        self::assertLessThanOrEqual(LeaderboardHistogramBuilder::MAX_BINS + 2, count($data['labels']));
        self::assertSame('00:50:00', $data['labels'][0]);

        // Tooltip titles name each bar's time range, markers: the median only - the viewer has no row
        $markers = self::markers($options);
        // 51 solvers a minute apart: two-minute bars are the narrowest that fit
        self::assertCount(count($data['labels']), self::ranges($options));
        self::assertSame('00:50:00 – 00:52:00', self::ranges($options)[0]);
        self::assertSame(['Median'], array_column($markers, 'label'));
        self::assertSame('Puzzlers', self::yAxisTitle($options));

        self::assertStringStartsWith('Times of 51 puzzlers, median ', $chart->getSummary());
    }

    public function testViewerIsMarkedAndTheirBarOutlined(): void
    {
        $rows = self::soloRows(80);
        $chart = $this->chart($rows, viewerId: 'player-30');

        $data = $chart->getChart()->getData();
        $options = $chart->getChart()->getOptions();
        $markers = self::markers($options);
        self::assertSame(['Median', 'You'], array_column($markers, 'label'));

        // The viewer's bar is outlined, not filled red, and it is the bar the "You" marker points into
        $viewerPosition = $markers[1]['position'];
        self::assertIsFloat($viewerPosition);
        self::assertSame(['index' => (int) floor($viewerPosition), 'color' => self::VIEWER_COLOR], self::pluginOptions($options)['highlight']);

        foreach ([0, 1] as $dataset) {
            self::assertIsArray($data['datasets']);
            self::assertIsArray($data['datasets'][$dataset]);
            self::assertIsArray($data['datasets'][$dataset]['backgroundColor']);
            self::assertNotContains(self::VIEWER_COLOR, $data['datasets'][$dataset]['backgroundColor']);
        }

        self::assertStringContainsString('Your time: 01:20:00.', $chart->getSummary());
    }

    public function testDistributionSplitsFirstTriesFromRepeats(): void
    {
        // 60 solvers a minute apart from 51 minutes, the 30 fastest on their first try; five-minute bars from 00:50
        $chart = $this->chart(self::soloRows(60, firstAttempts: 30));

        $data = $chart->getChart()->getData();
        $options = $chart->getChart()->getOptions();

        // 00:50-00:55 holds solvers 1-4, then five per bar: 01:20-01:25 solvers 30 (1st try) to 34, the last bar solver 60
        self::assertSame([4, 5, 5, 5, 5, 5, 1, 0, 0, 0, 0, 0, 0], self::datasetValues($data, 0));
        self::assertSame([0, 0, 0, 0, 0, 0, 4, 5, 5, 5, 5, 5, 1], self::datasetValues($data, 1));

        // One tooltip line per bar; a part that would be 0 is left out
        $tooltips = self::pluginOptions($options)['tooltips'];
        self::assertIsArray($tooltips);
        self::assertSame('4 puzzlers · 4 first tries', $tooltips[0]);
        self::assertSame('5 puzzlers · 1 first try · 4 repeats', $tooltips[6]);
        self::assertSame('5 puzzlers · 5 repeats', $tooltips[7]);
        self::assertSame('1 puzzler · 1 repeat', $tooltips[12]);

        // Stacked, one tooltip per bar, and the site's own word for a first attempt in the legend
        self::assertIsArray($options['scales']);
        self::assertIsArray($options['scales']['x']);
        self::assertIsArray($options['scales']['y']);
        self::assertTrue($options['scales']['x']['stacked']);
        self::assertTrue($options['scales']['y']['stacked']);
        self::assertSame(['mode' => 'index', 'intersect' => false], $options['interaction']);
        self::assertSame(['1st try', 'Repeat'], array_column($chart->getLegend(), 'label'));
    }

    public function testLegendOnlyWhenTheBarsShowBothColours(): void
    {
        // "1st tries only", or a board where nobody marked a first try: one colour, nothing to tell apart
        self::assertSame([], $this->chart(self::soloRows(60, firstAttempts: 60))->getLegend());
        self::assertSame([], $this->chart(self::soloRows(60))->getLegend());

        self::assertCount(2, $this->chart(self::soloRows(60, firstAttempts: 1))->getLegend());
    }

    public function testFoldedTailsUseTheLighterColoursOfBoth(): void
    {
        // Like the slow-outlier case of the builder: 100 solvers plus one of ten hours
        $rows = self::soloRows(100);
        $rows['player-slow'] = [self::solver('slow', 36000, firstAttempt: true)];

        $data = $this->chart($rows)->getChart()->getData();

        self::assertIsArray($data['datasets']);
        self::assertIsArray($data['datasets'][0]);
        self::assertIsArray($data['datasets'][1]);
        self::assertIsArray($data['datasets'][0]['backgroundColor']);
        self::assertIsArray($data['datasets'][1]['backgroundColor']);
        $firstTryColors = array_values($data['datasets'][0]['backgroundColor']);
        $repeatColors = array_values($data['datasets'][1]['backgroundColor']);

        // The last bar is the folded slow tail
        self::assertSame('rgba(105, 179, 254, 0.3)', $firstTryColors[count($firstTryColors) - 1]);
        self::assertSame('rgba(254, 105, 106, 0.3)', $repeatColors[count($repeatColors) - 1]);
        self::assertSame('rgba(105, 179, 254, 0.6)', $firstTryColors[0]);
        self::assertSame('rgba(254, 105, 106, 0.6)', $repeatColors[0]);
    }

    public function testPairsAreCountedAsPairsAndTheViewerFoundAmongTheMembers(): void
    {
        $rows = [];

        for ($i = 1; $i <= 60; $i++) {
            $rows['pair-' . $i] = [new PuzzleSolversGroup(
                timeId: 'time-' . $i,
                addedByPlayerId: 'player-a' . $i,
                teamId: 'team-' . $i,
                time: 2400 + 30 * $i,
                players: [
                    new Puzzler(playerId: 'player-a' . $i, playerName: 'A' . $i, playerCode: 'a' . $i, playerCountry: null, isPrivate: false),
                    new Puzzler(playerId: 'player-b' . $i, playerName: 'B' . $i, playerCode: 'b' . $i, playerCountry: null, isPrivate: false),
                ],
                finishedAt: null,
                trackedAt: new DateTimeImmutable('2026-01-01'),
                firstAttempt: false,
                unboxed: false,
                competitionId: null,
                competitionShortcut: null,
                competitionName: null,
                competitionSlug: null,
            )];
        }

        $chart = $this->chart($rows, viewerId: 'player-b10', category: 'duo');

        self::assertSame('Pairs', self::yAxisTitle($chart->getChart()->getOptions()));
        self::assertSame(['Median', 'You'], array_column(self::markers($chart->getChart()->getOptions()), 'label'));
        self::assertStringStartsWith('Times of 60 pairs, median ', $chart->getSummary());
    }

    /**
     * @param array<string, array<PuzzleSolver|PuzzleSolversGroup>> $rows
     */
    private function chart(array $rows, null|string $viewerId = null, string $category = 'solo'): PuzzleTimesChart
    {
        $container = self::getContainer();

        $chart = new PuzzleTimesChart(
            $container->get(ChartBuilderInterface::class),
            $container->get(LeaderboardHistogramBuilder::class),
            $container->get(PuzzlingTimeFormatter::class),
            $container->get(TranslatorInterface::class),
        );
        $chart->results = $rows;
        $chart->playerId = $viewerId;
        $chart->category = $category;

        return $chart;
    }

    /**
     * Solver n finishes in 50 minutes + n minutes; the $firstAttempts fastest on their first try
     *
     * @return array<string, array<PuzzleSolver>>
     */
    private static function soloRows(int $count, int $firstAttempts = 0): array
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows['player-' . $i] = [self::solver((string) $i, 3000 + 60 * $i, firstAttempt: $i <= $firstAttempts)];
        }

        return $rows;
    }

    private static function solver(string $id, int $time, bool $firstAttempt = false): PuzzleSolver
    {
        return new PuzzleSolver(
            timeId: 'time-' . $id,
            puzzleId: 'puzzle',
            playerId: 'player-' . $id,
            playerName: 'Solver ' . $id,
            playerCode: 'solver' . $id,
            playerCountry: null,
            time: $time,
            finishedAt: null,
            trackedAt: new DateTimeImmutable('2026-01-01'),
            firstAttempt: $firstAttempt,
            unboxed: false,
            isPrivate: false,
            competitionId: null,
            competitionShortcut: null,
            competitionName: null,
            competitionSlug: null,
        );
    }

    /**
     * @param array<mixed> $data
     * @return array<int>
     */
    private static function datasetValues(array $data, int $dataset = 0): array
    {
        self::assertIsArray($data['datasets']);
        self::assertIsArray($data['datasets'][$dataset]);
        self::assertIsArray($data['datasets'][$dataset]['data']);

        /** @var array<int> $values */
        $values = $data['datasets'][$dataset]['data'];

        return $values;
    }

    /**
     * @param array<mixed> $options
     * @return array<mixed>
     */
    private static function pluginOptions(array $options): array
    {
        self::assertIsArray($options['plugins']);
        self::assertIsArray($options['plugins']['leaderboardMarkers']);

        return $options['plugins']['leaderboardMarkers'];
    }

    /**
     * @param array<mixed> $options
     * @return list<array<string, mixed>>
     */
    private static function markers(array $options): array
    {
        self::assertIsArray($options['plugins']);
        self::assertIsArray($options['plugins']['leaderboardMarkers']);
        self::assertIsArray($options['plugins']['leaderboardMarkers']['markers']);

        /** @var list<array<string, mixed>> $markers */
        $markers = $options['plugins']['leaderboardMarkers']['markers'];

        return $markers;
    }

    /**
     * @param array<mixed> $options
     * @return array<mixed>
     */
    private static function ranges(array $options): array
    {
        self::assertIsArray($options['plugins']);
        self::assertIsArray($options['plugins']['leaderboardMarkers']);
        self::assertIsArray($options['plugins']['leaderboardMarkers']['ranges']);

        return $options['plugins']['leaderboardMarkers']['ranges'];
    }

    /**
     * @param array<mixed> $options
     */
    private static function yAxisTitle(array $options): mixed
    {
        self::assertIsArray($options['scales']);
        self::assertIsArray($options['scales']['y']);
        self::assertIsArray($options['scales']['y']['title']);

        return $options['scales']['y']['title']['text'];
    }
}
