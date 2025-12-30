<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetStatistics;
use SpeedPuzzling\Web\Results\GlobalStatistics as GlobalStatisticsResult;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class GlobalStatistics
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $timespan = 'this_month';

    public function __construct(
        readonly private GetStatistics $getStatistics,
    ) {
    }

    public function getStatistics(): GlobalStatisticsResult
    {
        return match ($this->timespan) {
            'this_month' => $this->getStatistics->globallyInMonth(
                $this->getCurrentMonth(),
                $this->getCurrentYear(),
            ),
            'last_month' => $this->getStatistics->globallyInMonth(
                $this->getLastMonth(),
                $this->getLastYear(),
            ),
            'all_time' => $this->getStatistics->globally(),
            default => $this->getStatistics->globally(),
        };
    }

    #[LiveAction]
    public function changeTimespan(#[LiveArg] string $timespan): void
    {
        if (in_array($timespan, ['this_month', 'last_month', 'all_time'], true)) {
            $this->timespan = $timespan;
        }
    }

    private function getCurrentMonth(): int
    {
        return (int) date('m');
    }

    private function getCurrentYear(): int
    {
        return (int) date('Y');
    }

    private function getLastMonth(): int
    {
        $currentMonth = $this->getCurrentMonth();

        if ($currentMonth === 1) {
            return 12;
        }

        return $currentMonth - 1;
    }

    private function getLastYear(): int
    {
        $currentMonth = $this->getCurrentMonth();

        if ($currentMonth === 1) {
            return $this->getCurrentYear() - 1;
        }

        return $this->getCurrentYear();
    }
}
