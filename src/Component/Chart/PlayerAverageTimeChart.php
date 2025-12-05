<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Chart;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPlayerChartData;
use SpeedPuzzling\Web\Value\ChartTimePeriodType;
use Symfony\Contracts\Translation\TranslatorInterface;
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
    public string $interval = 'month';

    #[LiveProp(writable: true)]
    public null|int $pieces = null;

    #[LiveProp(writable: true)]
    public bool $onlyFirstTries = false;

    /** @var array<int>|null */
    public null|array $availablePieces = null;

    public function __construct(
        readonly private ChartBuilderInterface $chartBuilder,
        readonly private GetPlayerChartData $getPlayerChartData,
        readonly private TranslatorInterface $translator,
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

        $pieces = $this->getPieces();

        $playerData = $this->getPlayerChartData->getForPlayer(
            playerId: $playerId,
            brandId: $brand,
            periodType: $period,
            pieces: $pieces,
            onlyFirstTries: $this->onlyFirstTries,
        );

        foreach ($playerData as $data) {
            if ($period === ChartTimePeriodType::Week) {
                $labels[] = 'W' . $data->period->format('W/Y');
            } else {
                $labels[] = $data->period->format('m/y');
            }

            $chartData[] = $data->time;
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
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
            'scales' => [
                'x' => [
                    'grid' => [
                        'display' => false,
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => false,
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
            'all' => $this->translator->trans('statistics.all_brands'),
        ];

        $playerId = $this->playerId;
        assert($playerId !== null);

        $brands = $this->getPlayerChartData->getBrandsSolvedSoloByPlayer($playerId, $this->getPieces(), $this->onlyFirstTries);

        foreach ($brands as $brandId => $brandName) {
            $brandChoices[$brandId] = $brandName;
        }

        return $brandChoices;
    }

    /**
     * @return array<int>
     */
    public function availablePieces(): array
    {
        if ($this->availablePieces === null) {
            $playerId = $this->playerId;
            assert($playerId !== null);

            $brand = Uuid::isValid($this->brand ?? '') ? $this->brand : null;

            $this->availablePieces = $this->getPlayerChartData->getSolvedPiecesCount($playerId, $brand, $this->onlyFirstTries);
        }

        return $this->availablePieces;
    }

    public function getPieces(): int
    {
        if ($this->pieces !== null) {
            return $this->pieces;
        }

        $availablePieces = $this->availablePieces();

        if (count($availablePieces) > 0) {
            $this->pieces = $availablePieces[array_key_first($availablePieces)];
        } else {
            $this->pieces = 500;
        }

        return $this->pieces;
    }
}
