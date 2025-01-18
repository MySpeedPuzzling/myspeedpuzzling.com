<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Chart;

use SpeedPuzzling\Web\Query\GetManufacturers;
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

    public function __construct(
        readonly private GetManufacturers $getManufacturers,
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function getChartData(): array
    {
        return [
            'labels' => [
                'Week 1', 'Week 2', 'Week 3', 'Week 4',
                'Week 5', 'Week 6', 'Week 7', 'Week 8',
                'Week 9', 'Week 10', 'Week 11', 'Week 12',
                'Week 13', 'Week 14', 'Week 15', 'Week 16',
            ],
            'datasets' => [
                [
                    'label' => '500 pieces Average Time per Week',
                    'data' => [
                        4815, 5630, 5020, 5235, // Week 1 to Week 4
                        4805, 4950, 5105, 4680, // Week 5 to Week 8
                        5200, 5400, 5505, 5300, // Week 9 to Week 12
                        4900, 5000, 5200, 4800, // Week 13 to Week 16
                    ],
                    'borderColor' => '#fe4042',
                    'borderWidth' => 2,
                    'backgroundColor' => 'rgba(254, 64, 66, 0.2)',
                    'fill' => true,
                ],
            ],
        ];
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
