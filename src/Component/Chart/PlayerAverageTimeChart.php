<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Chart;

use SpeedPuzzling\Web\Query\GetManufacturers;
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
        readonly private GetManufacturers $getManufacturers,
        readonly private ChartBuilderInterface $chartBuilder,
    ) {
    }

    public function getChart(): Chart
    {

        $data = [];
        $labels = [];
        $label = '';

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);

        if ($this->interval === 'week') {
            $label = '500 pieces Average Time per Week';

            for ($i=1; $i<=70; $i++) {
                $labels[] = 'W ' . $i;
                $data[] = rand(2000, 4000);
            }
        }

        if ($this->interval === 'month') {
            $label = '500 pieces Average Time per Month';

            for ($i=1; $i<=18; $i++) {
                $labels[] = 'M ' . $i;
                $data[] = rand(2000, 4000);
            }
        }

        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => $label,
                    'data' => $data,
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

        // TODO: only brands that player have solved
        foreach ($this->getManufacturers->onlyApprovedOrAddedByPlayer() as $manufacturer) {
            $brandChoices[$manufacturer->manufacturerId] = $manufacturer->manufacturerName;
        }

        return $brandChoices;
    }
}
