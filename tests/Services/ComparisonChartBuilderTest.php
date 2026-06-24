<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use SpeedPuzzling\Web\Results\ComparisonView;
use SpeedPuzzling\Web\Services\ComparisonBuilder;
use SpeedPuzzling\Web\Services\ComparisonChartBuilder;
use SpeedPuzzling\Web\Tests\DataFixtures\ComparisonFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\ComparisonFilter;
use SpeedPuzzling\Web\Value\ComparisonMode;
use SpeedPuzzling\Web\Value\ComparisonSubject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\Chartjs\Model\Chart;

final class ComparisonChartBuilderTest extends KernelTestCase
{
    private ComparisonChartBuilder $chartBuilder;

    private ComparisonBuilder $builder;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->chartBuilder = self::getContainer()->get(ComparisonChartBuilder::class);
        $this->builder = self::getContainer()->get(ComparisonBuilder::class);
    }

    private function view(bool $withDifficulty = true): ComparisonView
    {
        return $this->builder->build(
            [new ComparisonSubject(ComparisonFixture::CMP_A), new ComparisonSubject(ComparisonFixture::CMP_B)],
            ComparisonMode::Solo,
            new ComparisonFilter(),
            withDifficulty: $withDifficulty,
            selfPlayerId: null,
        );
    }

    public function testWinsChartIsASingleDatasetBar(): void
    {
        $chart = $this->chartBuilder->build('wins', $this->view());

        self::assertSame(Chart::TYPE_BAR, $chart->getType());
        self::assertCount(1, $this->datasets($chart));
        self::assertCount(2, $this->labels($chart)); // one label per subject
    }

    public function testGroupedChartsHaveOneDatasetPerSubject(): void
    {
        foreach (['pieces', 'puzzles'] as $type) {
            $chart = $this->chartBuilder->build($type, $this->view());
            self::assertSame(Chart::TYPE_BAR, $chart->getType());
            self::assertCount(2, $this->datasets($chart), "Chart {$type} should have one dataset per subject");
        }
    }

    public function testDifficultyChartIsRadar(): void
    {
        $chart = $this->chartBuilder->build('difficulty', $this->view());

        self::assertSame(Chart::TYPE_RADAR, $chart->getType());
        self::assertCount(2, $this->datasets($chart));
    }

    public function testHasDataRequiresAtLeastTwoSubjects(): void
    {
        $singleSubjectView = $this->builder->build(
            [new ComparisonSubject(ComparisonFixture::CMP_A)],
            ComparisonMode::Solo,
            new ComparisonFilter(),
            withDifficulty: true,
            selfPlayerId: null,
        );

        self::assertFalse($this->chartBuilder->hasData('wins', $singleSubjectView));
        self::assertTrue($this->chartBuilder->hasData('wins', $this->view()));
    }

    public function testDifficultyChartNeedsDifficultyData(): void
    {
        // Existing players share PUZZLE_500_03, which has a computed difficulty.
        self::assertTrue($this->chartBuilder->hasData('difficulty', $this->existingPlayersView(withDifficulty: true)));
        self::assertFalse($this->chartBuilder->hasData('difficulty', $this->existingPlayersView(withDifficulty: false)));
    }

    private function existingPlayersView(bool $withDifficulty): ComparisonView
    {
        return $this->builder->build(
            [new ComparisonSubject(PlayerFixture::PLAYER_REGULAR), new ComparisonSubject(PlayerFixture::PLAYER_PRIVATE)],
            ComparisonMode::Solo,
            new ComparisonFilter(),
            withDifficulty: $withDifficulty,
            selfPlayerId: null,
        );
    }

    /**
     * @return array<mixed>
     */
    private function datasets(Chart $chart): array
    {
        $datasets = $chart->getData()['datasets'] ?? null;
        self::assertIsArray($datasets);

        return $datasets;
    }

    /**
     * @return array<mixed>
     */
    private function labels(Chart $chart): array
    {
        $labels = $chart->getData()['labels'] ?? null;
        self::assertIsArray($labels);

        return $labels;
    }
}
