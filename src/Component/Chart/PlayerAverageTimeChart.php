<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Chart;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Query\GetPlayerChartData;
use SpeedPuzzling\Web\Value\ChartTimePeriodType;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class PlayerAverageTimeChart
{
    use DefaultActionTrait;

    #[LiveProp]
    public null|string $playerId = null;

    #[LiveProp(writable: true)]
    public null|string $brand = 'all';

    #[LiveProp(writable: true)]
    public string $interval = 'week';

    public function __construct(
        readonly private ChartBuilderInterface $chartBuilder,
        readonly private GetPlayerChartData $getPlayerChartData,
    ) {
    }

    public function getChart(): Chart
    {
        $chartData = [];
        $labels = [];
        $period = $this->interval === 'month' ? ChartTimePeriodType::Month : ChartTimePeriodType::Week;
        $brand = Uuid::isValid($this->brand ?? '') ? $this->brand : null;
        $playerId = $this->playerId;
        assert($playerId !== null);

        $label = sprintf('500 pieces average time per %s',
            $period === ChartTimePeriodType::Week ? 'week' : 'month',
        );

        $playerData = $this->getPlayerChartData->getForPlayer(
            playerId: $playerId,
            brandId: $brand,
            periodType: $period,
        );

        foreach ($playerData as $data) {
            $labels[] = $data->period->format($period === ChartTimePeriodType::Week ? 'W/Y' : 'm/Y');
            $chartData[] = $data->time;
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => $label,
                    'data' => $chartData,
                    'borderColor' => '#fe4042',
                    'borderWidth' => 2,
                    'backgroundColor' => 'rgba(254, 64, 66, 0.2)',
                    'fill' => true,
                    'cubicInterpolationMode' => 'monotone',
                    'tension' => 0.4,
                ],
            ],
        ]);

        $chart->setOptions([
            'plugins' => [
                'legend' => [
                    'align' => 'end',
                ],
            ],
        ]);

        return $chart;
    }

    /**
     * @return array<string, string>
     */
    public function availableBrands(): array
    {
        $brandChoices = [
            'all' => 'All brands',
        ];

        $playerId = $this->playerId;
        assert($playerId !== null);

        foreach ($this->getPlayerChartData->getBrandsSolvedSoloByPlayer($playerId) as $brandId => $brandName) {
            $brandChoices[$brandId] = $brandName;
        }

        return $brandChoices;
    }
}
