<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetMostActivePlayers;
use SpeedPuzzling\Web\Results\MostActivePlayer;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class MostActiveSoloPlayers
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $timespan = 'this_month';

    #[LiveProp]
    public int $limit = 20;

    #[LiveProp]
    public int $showLimit = 4;

    public function __construct(
        readonly private GetMostActivePlayers $getMostActivePlayers,
    ) {
    }

    /**
     * @return array<MostActivePlayer>
     */
    public function getPlayers(): array
    {
        return match ($this->timespan) {
            'this_month' => $this->getMostActivePlayers->mostActiveSoloPlayersInMonth(
                $this->limit,
                $this->getCurrentMonth(),
                $this->getCurrentYear(),
            ),
            'last_month' => $this->getMostActivePlayers->mostActiveSoloPlayersInMonth(
                $this->limit,
                $this->getLastMonth(),
                $this->getLastYear(),
            ),
            'all_time' => $this->getMostActivePlayers->mostActiveSoloPlayers($this->limit),
            default => [],
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
