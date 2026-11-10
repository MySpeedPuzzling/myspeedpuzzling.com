<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component\Chart;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Component\Chart\PlayerAverageTimeChart;
use SpeedPuzzling\Web\Query\GetPlayerChartData;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;

/**
 * The profile chart's two filters: which piece counts (the first one is the chart's default) and which brands, how many
 * solo times each. Fixtures in solo times - PLAYER_REGULAR: 500 Ravensburger 12 (8 first tries), 1000 Trefl 3 (2),
 * 300 / 1500 / 2000 Ravensburger 1 each; PLAYER_PRIVATE: 500 Ravensburger 8 + Trefl 1, 1000 Ravensburger 1 + Trefl 1,
 * 300 / 2000 Ravensburger 1 each.
 */
final class PlayerAverageTimeChartTest extends KernelTestCase
{
    public function testPieceCountsMostSolvedFirstEqualCountsSmallestFirst(): void
    {
        self::assertSame([500, 1000, 300, 1500, 2000], $this->chart(PlayerFixture::PLAYER_REGULAR)->availablePieces());
        self::assertSame([500, 1000, 300, 2000], $this->chart(PlayerFixture::PLAYER_PRIVATE)->availablePieces());
    }

    public function testTheMostSolvedPieceCountIsTheChartsDefault(): void
    {
        self::assertSame(500, $this->chart(PlayerFixture::PLAYER_REGULAR)->getPieces());
    }

    public function testPieceCountsOfOneBrand(): void
    {
        $chart = $this->chart(PlayerFixture::PLAYER_REGULAR);
        $chart->brand = ManufacturerFixture::MANUFACTURER_TREFL;

        self::assertSame([1000], $chart->availablePieces());
    }

    public function testBrandsOfThePieceCountMostSolvedFirstEqualCountsByBrand(): void
    {
        $chart = $this->chart(PlayerFixture::PLAYER_PRIVATE);

        $chart->pieces = 500;
        self::assertSame(
            ['all', ManufacturerFixture::MANUFACTURER_RAVENSBURGER, ManufacturerFixture::MANUFACTURER_TREFL],
            array_keys($chart->availableBrands()),
        );
        self::assertStringEndsWith('(8)', $chart->availableBrands()[ManufacturerFixture::MANUFACTURER_RAVENSBURGER]);

        $chart = $this->chart(PlayerFixture::PLAYER_PRIVATE);
        $chart->pieces = 1000;
        self::assertSame(
            ['all', ManufacturerFixture::MANUFACTURER_RAVENSBURGER, ManufacturerFixture::MANUFACTURER_TREFL],
            array_keys($chart->availableBrands()),
        );
    }

    public function testASelectedBrandDoesNotNarrowTheBrandFilterItself(): void
    {
        $chart = $this->chart(PlayerFixture::PLAYER_PRIVATE);
        $chart->pieces = 500;
        $chart->brand = ManufacturerFixture::MANUFACTURER_TREFL;

        self::assertCount(3, $chart->availableBrands());
    }

    public function testOnlyFirstTriesCountsFirstTries(): void
    {
        $chart = $this->chart(PlayerFixture::PLAYER_REGULAR);
        $chart->onlyFirstTries = true;

        self::assertSame([500, 1000, 300, 1500, 2000], $chart->availablePieces());
        self::assertStringEndsWith('(8)', $chart->availableBrands()[ManufacturerFixture::MANUFACTURER_RAVENSBURGER]);
    }

    public function testSuspiciousTimesAreNotCounted(): void
    {
        // SuspiciousTimesFixture: this player's only solo time is suspicious
        $playerId = self::getContainer()->get(Connection::class)->fetchOne("SELECT id FROM player WHERE code = 'marked1'");
        self::assertIsString($playerId);

        $chart = $this->chart($playerId);

        self::assertSame([], $chart->availablePieces());
        self::assertSame(['all'], array_keys($chart->availableBrands()));
        self::assertSame(500, $chart->getPieces());
    }

    public function testBothFiltersCostOneStatement(): void
    {
        $chart = $this->chart(PlayerFixture::PLAYER_REGULAR);

        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        $chart->availablePieces();
        $chart->availableBrands();

        /** @var list<array{sql: string}> $executed */
        $executed = $debugDataHolder->getData()['default'] ?? [];

        self::assertCount(1, $executed);
    }

    private function chart(string $playerId): PlayerAverageTimeChart
    {
        self::bootKernel();
        $container = self::getContainer();

        $chart = new PlayerAverageTimeChart(
            $container->get(ChartBuilderInterface::class),
            $container->get(GetPlayerChartData::class),
            $container->get(TranslatorInterface::class),
        );
        $chart->playerId = $playerId;

        return $chart;
    }
}
